<?php
/**
 * SMS gateway wrapper.
 * Har message sms_logs table me log hota hai, driver chahe koi bhi ho.
 */
class Sms
{
    /** Template body me {placeholders} bharo. */
    public static function render(string $code, array $vars): string
    {
        $tpl = Database::one('SELECT body FROM sms_templates WHERE code = ? AND active = 1', [$code]);
        $body = $tpl['body'] ?? '';
        $vars['school'] = $vars['school'] ?? Config::setting('school_name', 'School');

        foreach ($vars as $k => $v) {
            $body = str_replace('{' . $k . '}', (string) $v, $body);
        }
        return preg_replace('/\{[a-z_]+\}/', '', $body);
    }

    /** Template se bhejo. */
    public static function sendTemplate(string $code, string $mobile, array $vars, ?int $studentId = null): array
    {
        $msg = self::render($code, $vars);
        if (trim($msg) === '') {
            return ['status' => 'failed', 'response' => 'Template not found or empty: ' . $code];
        }
        return self::send($mobile, $msg, $code, $studentId);
    }

    /** Raw message bhejo. */
    public static function send(string $mobile, string $message, ?string $template = null, ?int $studentId = null): array
    {
        $mobile = preg_replace('/\D/', '', $mobile);
        if (strlen($mobile) < 10) {
            return ['status' => 'failed', 'response' => 'Invalid mobile number'];
        }

        $cfg    = Config::get('sms');
        $driver = $cfg['driver'] ?? 'log';

        try {
            $result = match ($driver) {
                'msg91'    => self::viaMsg91($mobile, $message, $cfg),
                'fast2sms' => self::viaFast2Sms($mobile, $message, $cfg),
                'twilio'   => self::viaTwilio($mobile, $message, $cfg),
                default    => self::viaLog($mobile, $message),
            };
        } catch (Throwable $e) {
            $result = ['status' => 'failed', 'response' => $e->getMessage()];
        }

        Database::insert('sms_logs', [
            'student_id' => $studentId,
            'mobile'     => $mobile,
            'template'   => $template,
            'message'    => $message,
            'status'     => $result['status'],
            'response'   => substr($result['response'], 0, 255),
            'sent_by'    => Auth::user()['id'] ?? null,
        ]);

        return $result;
    }

    // ---------- drivers ----------

    private static function viaLog(string $mobile, string $message): array
    {
        $dir = __DIR__ . '/../storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $dir . '/sms.log',
            date('Y-m-d H:i:s') . " | $mobile | $message" . PHP_EOL,
            FILE_APPEND
        );
        return ['status' => 'sent', 'response' => 'Logged (dev driver, no real SMS sent)'];
    }

    private static function viaMsg91(string $mobile, string $message, array $cfg): array
    {
        $params = http_build_query([
            'authkey' => $cfg['msg91']['auth_key'],
            'mobiles' => $cfg['msg91']['country'] . $mobile,
            'message' => $message,
            'sender'  => $cfg['sender_id'],
            'route'   => $cfg['msg91']['route'],
            'country' => $cfg['msg91']['country'],
        ]);
        $res = self::http('https://api.msg91.com/api/sendhttp.php?' . $params);
        return ['status' => $res['ok'] ? 'sent' : 'failed', 'response' => $res['body']];
    }

    private static function viaFast2Sms(string $mobile, string $message, array $cfg): array
    {
        $res = self::http('https://www.fast2sms.com/dev/bulkV2', [
            'route'    => $cfg['fast2sms']['route'],
            'message'  => $message,
            'language' => 'english',
            'numbers'  => $mobile,
        ], ['authorization: ' . $cfg['fast2sms']['api_key']]);
        return ['status' => $res['ok'] ? 'sent' : 'failed', 'response' => $res['body']];
    }

    private static function viaTwilio(string $mobile, string $message, array $cfg): array
    {
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $cfg['twilio']['sid'] . '/Messages.json';
        $res = self::http($url, [
            'To'   => '+91' . $mobile,
            'From' => $cfg['twilio']['from'],
            'Body' => $message,
        ], [], $cfg['twilio']['sid'] . ':' . $cfg['twilio']['token']);
        return ['status' => $res['ok'] ? 'sent' : 'failed', 'response' => $res['body']];
    }

    private static function http(string $url, ?array $post = null, array $headers = [], ?string $basicAuth = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        if ($basicAuth) {
            curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return ['ok' => $code >= 200 && $code < 300, 'body' => $body ?: $err ?: 'HTTP ' . $code];
    }
}
