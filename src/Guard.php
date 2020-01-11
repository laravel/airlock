<?php

namespace Laravel\Airlock;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Laravel\Airlock\HasApiTokens;

class Guard
{
    /**
     * The authentication factory implementation.
     *
     * @var \Illuminate\Contracts\Auth\Factory
     */
    protected $auth;

    /**
     * The number of minutes tokens should be allowed to remain valid.
     *
     * @var int
     */
    protected $expiration;

    /**
     * Create a new guard instance.
     *
     * @param  \Illuminate\Contracts\Auth\Factory  $auth
     * @param  int  $expiration
     * @return void
     */
    public function __construct(AuthFactory $auth, $expiration = null)
    {
        $this->auth = $auth;
        $this->expiration = $expiration;
    }

    /**
     * Retrieve the authenticated user for the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function __invoke(Request $request)
    {
        if ($user = $this->auth->guard(config('airlock.guard', 'web'))->user()) {
            return $this->supportsTokens()
                        ? $user->withAccessToken(new TransientToken)
                        : $user;
        }

        if ($this->supportsTokens() && $token = $request->bearerToken()) {
            $model = Airlock::$personalAccessTokenModel;

            $accessToken = $model::where('token', hash('sha256', $token))->first();

            if (! $accessToken ||
                ($this->expiration &&
                 $accessToken->created_at->lte(now()->subMinutes($this->expiration)))) {
                return;
            }

            return $accessToken->user->withAccessToken(
                tap($accessToken->forceFill(['last_used_at' => now()]))->save()
            );
        }
    }

    /**
     * Determine if the user model supports API tokens.
     *
     * @return bool
     */
    protected function supportsTokens()
    {
        return in_array(HasApiTokens::class, class_uses_recursive(
            $this->auth->guard(config('airlock.guard', 'web'))->getProvider()->getModel()
        ));
    }
}
