<?php

namespace App\Console\Commands\Concerns;

/** Secrets leave a command only through a restricted file, never through stdout, logs or the process list. */
trait WritesSecretFile
{
    /** @param array<string, string> $values KEY => value */
    protected function writeSecretFile(string $path, array $values): bool
    {
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            return false;
        }

        $body = '';
        foreach ($values as $key => $value) {
            $body .= $key.'='.$value."\n";
        }

        // 0600 before the first byte is written: the secret is never readable by anyone else, not even briefly.
        $handle = fopen($path, 'cb');
        if ($handle === false) {
            return false;
        }

        chmod($path, 0600);
        ftruncate($handle, 0);
        $written = fwrite($handle, $body);
        fclose($handle);

        return $written === strlen($body);
    }
}
