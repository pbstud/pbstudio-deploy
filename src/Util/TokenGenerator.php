<?php
declare(strict_types=1);

namespace App\Util;

/**
 * Token Generator.
 *
 * @author JCHR <car.chr@gmail.com>
 */
class TokenGenerator
{
    /**
     * Generate token string.
     *
     * @return string
     */
    public static function generateToken(): string
    {
        try {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch (\Exception $e) {
            $token = md5(uniqid('', true));
        }

        return $token;
    }
}
