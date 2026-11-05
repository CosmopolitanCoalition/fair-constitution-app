<?php

/**
 * W-0523 - where the assistant's model runs. All values come from the environment.
 * With no endpoint set, the assistant answers from its phrase router and tools only.
 * The endpoint must speak the OpenAI chat-completions format (a self-hosted server,
 * a community model engine, or a cloud account).
 */
return [
    'endpoint' => env('ASSISTANT_ENDPOINT'),
    'api_key' => env('ASSISTANT_API_KEY'),
    'model' => env('ASSISTANT_MODEL'),
    'timeout' => (int) env('ASSISTANT_TIMEOUT', 60),
    'max_tool_rounds' => 4,
    'history_turns' => 12,
];
