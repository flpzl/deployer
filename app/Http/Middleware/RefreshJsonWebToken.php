<?php

namespace REBELinBLUE\Deployer\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Routing\Redirector;
use REBELinBLUE\Deployer\Events\JsonWebTokenExpired;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\JWTAuth;

/**
 * Middleware to ensure the JSON web token is still valid.
 */
class RefreshJsonWebToken
{
    /**
     * @var AuthFactory
     */
    protected $auth;

    /**
     * @var Dispatcher
     */
    private $dispatcher;

    /**
     * @var Redirector
     */
    private $redirector;

    /**
     * @var ResponseFactory
     */
    private $response;

    /**
     * @var JWTAuth
     */
    private $jwt;

    /**
     * @param JWTAuth         $jwt
     * @param Dispatcher      $dispatcher
     * @param Redirector      $redirector
     * @param ResponseFactory $response
     * @param AuthFactory     $auth
     */
    public function __construct(
        JWTAuth $jwt,
        Dispatcher $dispatcher,
        Redirector $redirector,
        ResponseFactory $response,
        AuthFactory $auth
    ) {
        $this->auth       = $auth;
        $this->dispatcher = $dispatcher;
        $this->redirector = $redirector;
        $this->response   = $response;
        $this->jwt        = $jwt;
    }

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param Closure                  $next
     * @param string|null              $guard
     *
     * @return \Illuminate\Http\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function handle($request, Closure $next, $guard = null)
    {
        $authenticated_user = $this->auth->guard($guard)->user();

        $has_valid_token = false;

        // Is the user has used "remember me" the token may not be in their session when they return
        if ($request->session()->has('jwt')) {
            $token = $request->session()->get('jwt');

            try {
                $token_user = $this->jwt->authenticate($token);

                if ($token_user->id !== $authenticated_user->id) {
                    throw new JWTException('Token does not belong to the authenticated user');
                }

                $has_valid_token = true;
            } catch (TokenExpiredException $e) {
                $has_valid_token = false;
            } catch (JWTException $e) {
                if ($request->ajax()) {
                    return $this->response->make('Unauthorized.', 401);
                }

                return $this->redirector->guest('login');
            }
        }

        // If there is no valid token, generate one
        if (!$has_valid_token) {
            $this->dispatcher->dispatch(new JsonWebTokenExpired($authenticated_user));
        }

        return $next($request);
    }
}
