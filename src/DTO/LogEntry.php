<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/log` menu.
 *
 * $time is preserved exactly as RouterOS reports it and is deliberately
 * NOT parsed into a date/time object. MikroTik's own documentation
 * confirms the console only shows a full date when the entry is not
 * from today ("if logs are printed on the same date when the log entry
 * was added, then only the time will be shown" — and even then, without
 * a year), so there is no single, safe date/time format to normalize
 * into without risking silent misinterpretation. Treat $time as a
 * display string, not a sortable/comparable value.
 *
 * $topics is likewise preserved as the raw string RouterOS returned. A
 * log entry can carry more than one topic (MikroTik's own example:
 * OSPF debug logs use "route, ospf, debug, raw"), but the exact
 * separator RouterOS's REST API uses in JSON was not independently
 * confirmed, so this class does not split it into a list — topicsList()
 * is offered as a best-effort convenience only.
 */
final class LogEntry
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $time,
        public readonly ?string $topics,
        public readonly ?string $message,
        public readonly array $raw,
    ) {
    }

    /**
     * Best-effort split of $topics on commas, trimmed. Not authoritative
     * — see this class's docblock. Returns an empty array if $topics is
     * null or empty.
     *
     * @return list<string>
     */
    public function topicsList(): array
    {
        if ($this->topics === null || trim($this->topics) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $this->topics)), static fn (string $t): bool => $t !== ''));
    }

    public function toArray(): array
    {
        return [
            'id'      => $this->id,
            'time'    => $this->time,
            'topics'  => $this->topics,
            'message' => $this->message,
        ];
    }
}
