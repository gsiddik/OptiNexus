<?php

namespace App\Services\Notification;

/**
 * Safe placeholder substitution only - `{{ dot.path }}` is resolved
 * against the context array and interpolated as plain text. There is no
 * expression evaluation, no server-side code execution, and no access to
 * anything outside the supplied context.
 */
class NotificationTemplateRenderer
{
    public function render(string $template, array $context): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $m) use ($context) {
            $value = $this->resolve($m[1], $context);

            return is_scalar($value) ? (string) $value : '';
        }, $template);
    }

    private function resolve(string $path, array $context): mixed
    {
        $value = $context;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
