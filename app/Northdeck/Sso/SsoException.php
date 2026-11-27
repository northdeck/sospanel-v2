<?php

namespace App\Northdeck\Sso;

use RuntimeException;

/** A sign-in that can't go ahead. The message is safe to show the person. */
final class SsoException extends RuntimeException {}
