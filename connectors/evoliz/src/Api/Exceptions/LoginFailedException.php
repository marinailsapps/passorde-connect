<?php

namespace Passorde\Api\Exceptions;

/**
 * The credentials were refused, as opposed to a refused call made with a valid token.
 */
final class LoginFailedException extends ApiException {}
