<?php

namespace App\Services\Notification\Operational;

/** One operational notice, already translated: the same message goes to every recipient, only the greeting differs. */
final class OperationalMailMessage
{
    /**
     * @param  list<string>  $paragraphs
     * @param  array<string, string>  $facts  label => value, shown as a table
     */
    public function __construct(
        public readonly string $subject,
        public readonly array $paragraphs,
        public readonly array $facts = [],
        public readonly ?string $actionUrl = null,
    ) {}
}
