<?php
/*
llm_helpers.php — one request per image to a general-purpose multimodal model, plus the code that turns
its reply into a ranked list of font names. Used by run_llm_benchmark.php (to call and score) and by
rescore.php (to re-score from the saved raw replies). Plain curl, no SDKs; PHP 7.1+.

Every provider gets the same prompt and the same image, with the provider's default settings: no
temperature, no reasoning/thinking settings, no tools, no web search.
*/

function llm_providers() {
    return ['openai', 'anthropic', 'google'];
}

// The test files are named .jpg but are PNG inside; send the type the bytes actually are.
function llm_image_mime($bytes) {
    if (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") return 'image/png';
    if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
    if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') return 'image/webp';
    if (substr($bytes, 0, 3) === 'GIF') return 'image/gif';
    return 'image/jpeg';
}

// A curl handle that POSTs the image + prompt to the provider.
function llm_curl_handle($provider, $model, $apiKey, $imageBytes, $prompt, $maxTokens, $timeout) {
    $b64 = base64_encode($imageBytes);
    $mime = llm_image_mime($imageBytes);
    if ($provider === 'openai') {
        $url = 'https://api.openai.com/v1/responses';
        $headers = ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'];
        $body = [
            'model' => $model,
            'max_output_tokens' => $maxTokens,
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_image', 'image_url' => 'data:' . $mime . ';base64,' . $b64],
                    ['type' => 'input_text', 'text' => $prompt],
                ],
            ]],
        ];
    } elseif ($provider === 'anthropic') {
        $url = 'https://api.anthropic.com/v1/messages';
        $headers = ['x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'];
        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ]],
        ];
    } elseif ($provider === 'google') {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $headers = ['x-goog-api-key: ' . $apiKey, 'Content-Type: application/json'];
        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['inline_data' => ['mime_type' => $mime, 'data' => $b64]],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => ['maxOutputTokens' => $maxTokens],
        ];
    } else {
        throw new InvalidArgumentException("unknown provider: $provider");
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 30,
    ]);
    return $ch;
}

// Run several handles at once. $handles: [key => curl handle]. Returns [key => ['http', 'ms', 'body', 'curl_error']].
function llm_run_parallel($handles) {
    $mh = curl_multi_init();
    foreach ($handles as $ch) curl_multi_add_handle($mh, $ch);
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $status === CURLM_OK);

    $errors = [];
    while ($info = curl_multi_info_read($mh)) {
        if ($info['result'] !== CURLE_OK) $errors[llm_hid($info["handle"])] = curl_strerror($info['result']);
    }
    $out = [];
    foreach ($handles as $key => $ch) {
        $out[$key] = [
            'http'       => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'ms'         => (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
            'body'       => (string)curl_multi_getcontent($ch),
            'curl_error' => $errors[llm_hid($ch)] ?? "",
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// From a decoded 200 reply: the answer text, why the model stopped, the model it reports, token usage.
function llm_extract($provider, $raw) {
    $text = ''; $stop = null; $model = null; $usage = null;
    if (!is_array($raw)) return ['text' => '', 'stop' => null, 'model' => null, 'usage' => null];

    if ($provider === 'openai') {
        foreach (isset($raw['output']) ? $raw['output'] : [] as $item) {
            if (($item['type'] ?? '') !== 'message') continue;
            foreach (isset($item['content']) ? $item['content'] : [] as $c) {
                if (($c['type'] ?? '') === 'output_text') $text .= $c['text'];
                if (($c['type'] ?? '') === 'refusal') $stop = 'refusal';
            }
        }
        if ($stop === null) {
            $stop = $raw['status'] ?? null;
            if (isset($raw['incomplete_details']['reason'])) $stop .= ':' . $raw['incomplete_details']['reason'];
        }
        $model = $raw['model'] ?? null;
        $usage = $raw['usage'] ?? null;
    } elseif ($provider === 'anthropic') {
        foreach (isset($raw['content']) ? $raw['content'] : [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        $stop = $raw['stop_reason'] ?? null;
        $model = $raw['model'] ?? null;
        $usage = $raw['usage'] ?? null;
    } elseif ($provider === 'google') {
        $cand = isset($raw['candidates'][0]) ? $raw['candidates'][0] : [];
        foreach (isset($cand['content']['parts']) ? $cand['content']['parts'] : [] as $part) {
            if (!empty($part['thought'])) continue;
            if (isset($part['text'])) $text .= $part['text'];
        }
        $stop = $cand['finishReason'] ?? ($raw['promptFeedback']['blockReason'] ?? null);
        $model = $raw['modelVersion'] ?? null;
        $usage = $raw['usageMetadata'] ?? null;
    }
    return ['text' => $text, 'stop' => $stop, 'model' => $model, 'usage' => $usage];
}

// The model is asked for {"fonts": [...]}. Accept that object anywhere in the text (code fences,
// a sentence around it) and entries given as strings or as {"name": ...}. Returns up to $limit names.
function llm_parse_fonts($text, $limit = 20) {
    $candidates = [];
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m)) $candidates[] = $m[1];
    $a = strpos($text, '{'); $b = strrpos($text, '}');
    if ($a !== false && $b !== false && $b > $a) $candidates[] = substr($text, $a, $b - $a + 1);

    foreach ($candidates as $json) {
        $j = json_decode($json, true);
        if (!is_array($j)) continue;
        $list = isset($j['fonts']) ? $j['fonts'] : (isset($j['top_20']) ? $j['top_20'] : null);
        if (!is_array($list)) continue;
        $names = [];
        foreach ($list as $e) {
            if (is_array($e)) $e = $e['name'] ?? ($e['font'] ?? '');
            $e = trim((string)$e);
            if ($e !== '' && !in_array($e, $names, true)) $names[] = $e;
            if (count($names) >= $limit) break;
        }
        return $names;
    }
    return null;   // no parsable list in the reply
}

// Keys: env var first, then scripts/llm_keys.php (gitignored) returning ['openai' => ..., ...].
function llm_api_key($provider) {
    $env = ['openai' => 'OPENAI_API_KEY', 'anthropic' => 'ANTHROPIC_API_KEY', 'google' => 'GEMINI_API_KEY'];
    $k = getenv($env[$provider]);
    if ($k) return $k;
    $file = __DIR__ . '/llm_keys.php';
    if (file_exists($file)) {
        $keys = require $file;
        if (!empty($keys[$provider])) return $keys[$provider];
    }
    return null;
}

// curl handles are resources on PHP 7 and objects on PHP 8.
function llm_hid($ch) {
    return is_resource($ch) ? (int)$ch : spl_object_id($ch);
}
