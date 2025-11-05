<?php
namespace AISEO\AI;

class Gemini
{
    public static function summarize(string $prompt, array $context = []): array
    {
        // TODO: Wire up Gemini API integration in a future increment.
        return [
            'summary' => '',
            'context' => $context,
        ];
    }
}
