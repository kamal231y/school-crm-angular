<?php
/**
 * Global configuration.
 * Production me in values ko environment variables se load karein.
 */
return [
    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'name'    => getenv('DB_NAME') ?: 'school_crmkk',
        'user'    => getenv('DB_USER') ?: 'root',
        'pass'    => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],

    // Token lifetime in seconds (8 hours)
    'token_ttl' => 8 * 60 * 60,

    // Frontend origin allowed to call this API
    'cors_origin' => getenv('CORS_ORIGIN') ?: '*',

    /**
     * SMS gateway.
     * driver: log | msg91 | fast2sms | twilio
     *   log       -> kuch send nahi hota, storage/sms.log me likha jaata hai (dev ke liye)
     *   msg91     -> https://control.msg91.com
     *   fast2sms  -> https://www.fast2sms.com
     *   twilio    -> https://www.twilio.com
     */
    'sms' => [
        'driver'    => getenv('SMS_DRIVER') ?: 'log',
        'sender_id' => getenv('SMS_SENDER') ?: 'SUNRSE',
        'msg91'     => [
            'auth_key'    => getenv('MSG91_KEY') ?: '',
            'template_id' => getenv('MSG91_TEMPLATE') ?: '',
            'route'       => '4',
            'country'     => '91',
        ],
        'fast2sms'  => [
            'api_key' => getenv('FAST2SMS_KEY') ?: '',
            'route'   => 'q',
        ],
        'twilio'    => [
            'sid'   => getenv('TWILIO_SID') ?: '',
            'token' => getenv('TWILIO_TOKEN') ?: '',
            'from'  => getenv('TWILIO_FROM') ?: '',
        ],
    ],

    'uploads_dir' => __DIR__ . '/../storage/uploads',
];
