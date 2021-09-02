<?php
namespace Wty\Mongodb\Exceptions;


use MongoDB\Driver\Exception\AuthenticationException;
use Throwable;

class AuthException extends AuthenticationException
{

}