<?php

namespace REBELinBLUE\Deployer\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Queue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use REBELinBLUE\Deployer\Command as Stage;
use REBELinBLUE\Deployer\Deployment;
use REBELinBLUE\Deployer\DeployStep;
use REBELinBLUE\Deployer\Events\DeployFinished;
use REBELinBLUE\Deployer\Jobs\Job;
use REBELinBLUE\Deployer\Jobs\UpdateGitMirror;
use REBELinBLUE\Deployer\Project;
use REBELinBLUE\Deployer\Server;
use REBELinBLUE\Deployer\ServerLog;
use REBELinBLUE\Deployer\User;
use Symfony\Component\Process\Process;

/**
 * Deploys an actual project.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * TODO: rewrite this as it is doing way too much and is very messy now.
 * TODO: Move gitWrapperScript somewhere else as it is duplicated in UpdateGitReferences
 * TODO: Expand all parameters
 */
class DeployProject extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels, DispatchesJobs;

    private $deployment;
    private $private_key;
    private $cache_key;
    private $release_archive;
    private $release_id;

    /**
     * Create a new command instance.
     *
     * @param  Deployment    $deployment
     * @return DeployProject
     */
    public function __construct(Deployment $deployment)
    {
        $this->deployment = $deployment;
        $this->cache_key  = AbortDeployment::CACHE_KEY_PREFIX . $deployment->id;
    }

    /**
     * Overwrite the queue method to push to a different queue.
     *
     * @param  Queue         $queue
     * @param  DeployProject $command
     * @return void
     */
    public function queue(Queue $queue, $command)
    {
        $queue->pushOn('deployer-high', $command);
    }

    /**
     * Execute the command.
     *
     * @return void
     */
    public function handle()
    {
        $project = $this->deployment->project;

        $this->deployment->started_at = date('Y-m-d H:i:s');
        $this->deployment->status     = Deployment::DEPLOYING;
        $this->deployment->save();

        $project->status = Project::DEPLOYING;
        $project->save();

        $this->release_id = date('YmdHis', strtotime($this->deployment->started_at));

        $this->private_key = tempnam(storage_path() . '/app/', 'sshkey');
        file_put_contents($this->private_key, $project->private_key);

        $this->release_archive = storage_path('app/') . $this->deployment->project_id . '_' . $this->release_id . '.tar.gz';

        try {
            // If the build has been manually triggered update the git information from the remote repository
            if ($this->deployment->commit === Deployment::LOADING) {
                $this->updateRepoInfo();
            }

            foreach ($this->deployment->steps as $step) {
                $this->runStep($step);
            }

            $this->deployment->status = Deployment::COMPLETED;
            $project->status          = Project::FINISHED;
        } catch (\Exception $error) {
            $this->deployment->status = Deployment::FAILED;
            $project->status          = Project::FAILED;

            if ($error->getMessage() === 'Cancelled') {
                $this->deployment->status = Deployment::ABORTED;
            }

            $this->cancelPendingSteps($this->deployment->steps);

            if (isset($step)) {
                // Cleanup the release if it has not been activated
                if ($step->stage <= Stage::DO_ACTIVATE) {
                    $this->cleanupDeployment();
                } else {
                    $this->deployment->status = Deployment::COMPLETED_WITH_ERRORS;
                    $project->status          = Project::FINISHED;
                }
            }
        }

        $this->deployment->finished_at = date('Y-m-d H:i:s');
        $this->deployment->save();

        $project->last_run = date('Y-m-d H:i:s');
        $project->save();

        // Notify user or others the deployment has been finished
        event(new DeployFinished($project, $this->deployment));

        unlink($this->private_key);

        if (file_exists($this->release_archive)) {
            unlink($this->release_archive);
        }
    }

    /**
     * Clones the repository locally to get the latest log entry and updates
     * the deployment model.
     *
     * @return void
     */
    private function updateRepoInfo()
    {
        $this->dispatch(new UpdateGitMirror($this->deployment->project));

        $mirrorDir = $this->deployment->project->mirrorPath();

        $wrapper = tempnam(storage_path() . '/app/', 'gitssh');
        file_put_contents($wrapper, $this->gitWrapperScript($this->private_key));

        $workingDir = tempnam(storage_path() . '/app/', 'clone');
        unlink($workingDir);

        $tarFile = $this->release_archive;

        $cmd = <<< CMD
chmod +x "{$wrapper}" && \
export GIT_SSH="{$wrapper}" && \
git clone --recursive --quiet --reference {$mirrorDir} --branch %s --depth 1 %s {$workingDir} && \
cd {$workingDir} && \
git checkout %s --quiet && \
git log --pretty=format:"%%H%%x09%%an%%x09%%ae"
CMD;

        $process = new Process(sprintf(
            $cmd,
            $this->deployment->branch,
            $this->deployment->project->repository,
            $this->deployment->branch
        ));

        $process->setTimeout(null);
        $process->run();

        unlink($wrapper);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Could not get repository info - ' . $process->getErrorOutput());
        }

        $git_info = $process->getOutput();

        $parts = explode("\x09", $git_info);

        $this->deployment->commit          = $parts[0];
        $this->deployment->committer       = trim($parts[1]);
        $this->deployment->committer_email = trim($parts[2]);

        if (!$this->deployment->user_id && !$this->deployment->source) {
            $user = User::where('email', $this->deployment->committer_email)->first();

            if ($user) {
                $this->deployment->user_id = $user->id;
            }
        }

        $this->deployment->save();

        $cmd = <<< CMD
export GIT_DIR="{$workingDir}/.git" && \
export GIT_WORK_TREE="{$workingDir}" && \
cd {$workingDir} && \
(git archive --format=tar HEAD | gzip > {$tarFile}) && \
rm -rf {$workingDir}
CMD;

        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Could not get repository info - ' . $process->getErrorOutput());
        }
    }

    /**
     * Remove left over artifacts from a failed deploy on each server.
     *
     * @return void
     */
    private function cleanupDeployment()
    {
        $project = $this->deployment->project;

        foreach ($project->servers as $server) {
            if (!$server->deploy_code) {
                continue;
            }

            $root_dir = preg_replace('#/$#', '', $server->path);

            if (empty($root_dir)) {
                continue;
            }

            $releases_dir       = $root_dir . '/releases';
            $latest_release_dir = $releases_dir . '/' . $this->release_id;
            $remote_archive     = $root_dir . '/' . $this->release_id . '.tar.gz';

            $commands = [
                sprintf('cd %s', $root_dir),
                sprintf('[ -f %s ] && rm %s', $remote_archive, $remote_archive),
                sprintf('[ -d %s ] && rm -rf %s', $latest_release_dir, $latest_release_dir),
            ];

            $script = implode(PHP_EOL, $commands);

            $process = new Process($this->sshCommand($server, $script));
            $process->setTimeout(null);
            $process->run();
        }
    }

    /**
     * Finds all pending steps and marks them as cancelled.
     *
     * @return void
     */
    private function cancelPendingSteps()
    {
        foreach ($this->deployment->steps as $step) {
            foreach ($step->servers as $log) {
                if ($log->status === ServerLog::PENDING) {
                    $log->status = ServerLog::CANCELLED;
                    $log->save();
                }
            }
        }
    }

    /**
     * Executes the commands for a step.
     *
     * @param  DeployStep        $step
     * @throws \RuntimeException
     * @return void
     */
    private function runStep(DeployStep $step)
    {
        foreach ($step->servers as $log) {
            $log->status     = ServerLog::RUNNING;
            $log->started_at = date('Y-m-d H:i:s');
            $log->save();

            try {
                $server = $log->server;

                // FIME: Have a getFiles method here for transferring files
                $script = $this->getScript($step, $server, $log);

                $user = $server->user;
                if (isset($step->command)) {
                    $user = $step->command->user;
                }

                $failed    = false;
                $cancelled = false;

                if (!empty($script)) {
                    $process = new Process($this->sshCommand($server, $script, $user));
                    $process->setTimeout(null);

                    $output = '';
                    $process->run(function ($type, $output_line) use (&$output, &$log, $process, $step) {
                        if ($type === Process::ERR) {
                            $output .= $this->logError($output_line);
                        } else {
                            $output .= $this->logSuccess($output_line);
                        }

                        $log->output = $output;
                        $log->save();

                        // If there is a cache key, kill the process but leave the key
                        if ($step->stage <= Stage::DO_ACTIVATE && Cache::has($this->cache_key)) {
                            $process->stop(0, SIGINT);

                            $output .= $this->logError('SIGINT');
                        }
                    });

                    if (!$process->isSuccessful()) {
                        $failed = true;
                    }

                    $log->output = $output;
                }
            } catch (\Exception $e) {
                $log->output .= $this->logError('[' . $server->ip_address . ']: ' . $e->getMessage());
                $failed = true;
            }

            $log->status = ($failed ? ServerLog::FAILED : ServerLog::COMPLETED);

            // Check if there is a cache key and if so abort
            if (Cache::pull($this->cache_key) !== null) {

                // Only allow aborting if the release has not yet been activated
                if ($step->stage <= Stage::DO_ACTIVATE) {
                    $log->status = ServerLog::CANCELLED;

                    $cancelled = true;
                    $failed    = false;
                }
            }

            $log->finished_at = date('Y-m-d H:i:s');
            $log->save();

            // Throw an exception to prevent any more tasks running
            if ($failed) {
                throw new \RuntimeException('Failed');
            }

            // FIXME: This is a messy way to do it
            if ($cancelled) {
                throw new \RuntimeException('Cancelled');
            }
        }
    }

    /**
     * Generates the actual bash commands to run on the server.
     *
     * @param  DeployStep $step
     * @param  Server     $server
     * @return string
     */
    private function getScript(DeployStep $step, Server $server, $log = null)
    {
        $project = $this->deployment->project;

        $root_dir = preg_replace('#/$#', '', $server->path);

        // Precaution to make sure nothing accidentially runs at /
        if (empty($root_dir)) {
            return '';
        }

        $releases_dir = $root_dir . '/releases';

        $latest_release_dir = $releases_dir . '/' . $this->release_id;
        $release_shared_dir = $root_dir . '/shared';
        $remote_archive     = $root_dir . '/' . $this->release_id . '.tar.gz';

        $commands = false;

        if ($step->stage === Stage::DO_CLONE) {
            $this->sendFile($this->release_archive, $remote_archive, $server, $log);

            $commands = [
                sprintf('cd %s', $root_dir),
                sprintf('[ ! -d %s ] && mkdir %s', $releases_dir, $releases_dir),
                sprintf('[ ! -d %s ] && mkdir %s', $release_shared_dir, $release_shared_dir),
                sprintf('mkdir %s', $latest_release_dir),
                sprintf('cd %s', $latest_release_dir),
                sprintf('echo -e "\nExtracting...\n"'),
                sprintf(
                    'tar --warning=no-timestamp --gunzip --verbose --extract --file=%s --directory=%s',
                    $remote_archive,
                    $latest_release_dir
                ),
                sprintf('rm %s', $remote_archive),
            ];
        } elseif ($step->stage === Stage::DO_INSTALL) {
            // Install composer dependencies
            $commands = [
                sprintf('cd %s', $latest_release_dir),
                sprintf(
                    '( [ -f %s/composer.json ] && composer install --no-interaction --optimize-autoloader ' .
                    ($project->include_dev ? '' : '--no-dev ') .
                    '--prefer-dist --no-ansi --working-dir "%s" || exit 0 )',
                    $latest_release_dir,
                    $latest_release_dir
                ),
            ];

            // The shared file must be created in the install step
            $shareFileCommands = $this->shareFileCommands(
                $project,
                $latest_release_dir,
                $release_shared_dir
            );

            $commands = array_merge($commands, $shareFileCommands);

            // Write project file to release dir before install
            $projectFiles = $project->projectFiles;
            foreach ($projectFiles as $file) {
                if ($file->path) {
                    $filepath = $latest_release_dir . '/' . $file->path;
                    $this->sendFileFromString($server, $filepath, $file->content);
                    $commands[] = sprintf('chmod 0664 %s', $filepath);
                }
            }
        } elseif ($step->stage === Stage::DO_ACTIVATE) {
            // Activate latest release
            $commands = [
                sprintf('cd %s', $root_dir),
                sprintf('[ -h %s/latest ] && rm %s/latest', $root_dir, $root_dir),
                sprintf('ln -s %s %s/latest', $latest_release_dir, $root_dir),
            ];
        } elseif ($step->stage === Stage::DO_PURGE) {
            // Purge old releases
            $commands = [
                sprintf('cd %s', $releases_dir),
                sprintf('(ls -t|head -n %u;ls)|sort|uniq -u|xargs rm -rf', $project->builds_to_keep + 1),
            ];
        } else {
            // Custom step!
            $commands = $step->command->script;

            // FIXME: This should be on the deployment model
            // Set the deployer tags
            $deployer_email = '';
            $deployer_name  = 'webhook';
            if ($this->deployment->user) {
                $deployer_name  = $this->deployment->user->name;
                $deployer_email = $this->deployment->user->email;
            } elseif ($this->deployment->is_webhook && !empty($this->deployment->source)) {
                $deployer_name = $this->deployment->source;
            }

            $tokens = [
                '{{ release }}'         => $release_id,
                '{{ release_path }}'    => $latest_release_dir,
                '{{ project_path }}'    => $root_dir,
                '{{ branch }}'          => $this->deployment->branch,
                '{{ sha }}'             => $this->deployment->commit,
                '{{ short_sha }}'       => $this->deployment->short_commit,
                '{{ deployer_email }}'  => $deployer_email,
                '{{ deployer_name }}'   => $deployer_name,
                '{{ committer_email }}' => $this->deployment->committer_email,
                '{{ committer_name }}'  => $this->deployment->committer,
            ];

            $commands = str_replace(array_keys($tokens), array_values($tokens), $commands);
        }

        if (is_array($commands)) {
            $commands = implode(PHP_EOL, $commands);
        }

        $variables = '';
        foreach ($project->variables as $variable) {
            $key   = $variable->name;
            $value = $variable->value;

            $variables .= "export {$key}={$value}" . PHP_EOL;
        }

        return $variables . $commands;
    }

    /**
     * Generates an error string to log to the DB.
     *
     * @param  string $message
     * @return string
     */
    private function logError($message)
    {
        return '<error>' . $message . '</error>';
    }

    /**
     * Generates an general output string to log to the DB.
     *
     * @param  string $message
     * @return string
     */
    private function logSuccess($message)
    {
        return '<info>' . $message . '</info>';
    }

    /**
     * Generates the SSH command for running the script on a server.
     *
     * @param  Server $server
     * @param  string $script The script to run
     * @param  string $user
     * @return string
     */
    private function sshCommand(Server $server, $script, $user = null)
    {
        if (is_null($user)) {
            $user = $server->user;
        }

        if (config('app.debug')) {
            // Turn on verbose output so we can see all commands when in debug mode
            $script = 'set -v' . PHP_EOL . $script;
        }

        // Turn on quit on non-zero exit
        $script = 'set -e' . PHP_EOL . $script;

        return 'ssh -o CheckHostIP=no \
                 -o IdentitiesOnly=yes \
                 -o StrictHostKeyChecking=no \
                 -o PasswordAuthentication=no \
                 -o IdentityFile=' . $this->private_key . ' \
                 -p ' . $server->port . ' \
                 ' . $user . '@' . $server->ip_address . ' \'bash -s\' << \'EOF\'
                 ' . $script . '
EOF';
    }

    /**
     * Generates the content of a git bash script.
     *
     * @param  string $key_file_path The path to the public key to use
     * @return string
     */
    private function gitWrapperScript($key_file_path)
    {
        return <<<OUT
#!/bin/sh
ssh -o CheckHostIP=no \
    -o IdentitiesOnly=yes \
    -o StrictHostKeyChecking=no \
    -o PasswordAuthentication=no \
    -o IdentityFile={$key_file_path} $*

OUT;
    }

    /**
     * Sends a file to a remote server.
     *
     * @param  string           $local_file
     * @param  string           $remote_file
     * @param  Server           $server
     * @throws RuntimeException
     * @return void
     */
    private function sendFile($local_file, $remote_file, Server $server, $log = null)
    {
        $copy = sprintf(
            'rsync --verbose --compress --progress --out-format="Receiving %%n" -e "ssh -p %s ' .
            '-o CheckHostIP=no ' .
            '-o IdentitiesOnly=yes ' .
            '-o StrictHostKeyChecking=no ' .
            '-o PasswordAuthentication=no ' .
            '-i %s" ' .
            '%s %s@%s:%s',
            $server->port,
            $this->private_key,
            $local_file,
            $server->user,
            $server->ip_address,
            $remote_file
        );

        $process = new Process($copy);
        $process->setTimeout(null);
        ///$process->run();

        $output = '';
        $process->run(function ($type, $output_line) use (&$output, &$log) {
            if ($type === Process::ERR) {
                $output .= $this->logError($output_line);
            } else {
                // FIXME: Horrible hack
                $output_line = str_replace('received', 'xxx', $output_line);
                $output_line = str_replace('sent', 'received', $output_line);
                $output_line = str_replace('xxx', 'sent', $output_line);

                $output .= $this->logSuccess($output_line);
            }

            $log->output = $output;
            $log->save();
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }

        //return $process->getOutput();
    }

    /**
     * Send a string to server.
     *
     * @param  Server $server      target server
     * @param  string $remote_path remote filename
     * @param  string $content     the file content
     * @return void
     */
    private function sendFileFromString(Server $server, $remote_path, $content)
    {
        $tmp_file = tempnam(storage_path('app/'), 'tmpfile');
        file_put_contents($tmp_file, $content);

        // Upload the wrapper file
        $this->sendFile($tmp_file, $remote_path, $server);

        unlink($tmp_file);
    }

    /**
     * create the command for share files.
     *
     * @param  Project $project     the related project
     * @param  string  $release_dir current release dir
     * @param  string  $shared_dir  the shared dir
     * @return array
     */
    private function shareFileCommands(Project $project, $release_dir, $shared_dir)
    {
        $commands = [];
        foreach ($project->sharedFiles as $filecfg) {
            if ($filecfg->file) {
                $pathinfo = pathinfo($filecfg->file);
                $isDir    = false;

                if (substr($filecfg->file, 0, 1) === '/') {
                    $filecfg->file = substr($filecfg->file, 1);
                }

                if (substr($filecfg->file, -1) === '/') {
                    $isDir         = true;
                    $filecfg->file = substr($filecfg->file, 0, -1);
                }

                if (isset($pathinfo['extension'])) {
                    $filename = $pathinfo['filename'] . '.' . $pathinfo['extension'];
                } else {
                    $filename = $pathinfo['filename'];
                }

                $sourceFile = $shared_dir . '/' . $filename;
                $targetFile = $release_dir . '/' . $filecfg->file;

                if ($isDir) {
                    $commands[] = sprintf(
                        '[ -d %s ] && cp -pRn %s %s && rm -rf %s',
                        $targetFile,
                        $targetFile,
                        $sourceFile,
                        $targetFile
                    );
                    $commands[] = sprintf('[ ! -d %s ] && mkdir %s', $sourceFile, $sourceFile);
                } else {
                    $commands[] = sprintf(
                        '[ -f %s ] && cp -pRn %s %s && rm -rf %s',
                        $targetFile,
                        $targetFile,
                        $sourceFile,
                        $targetFile
                    );
                    $commands[] = sprintf('[ ! -f %s ] && touch %s', $sourceFile, $sourceFile);
                }

                $commands[] = sprintf('ln -s %s %s', $sourceFile, $targetFile);
            }
        }

        return $commands;
    }
}
