<?php

namespace App\Models;

class LogMigration2 extends \Eloquent
{

    protected $table                          = 'log_migrations2';
    protected $fillable                       = ["log_name", "batch", "log_status"];
    public static $rules                      = [
        'status'   => 'required|in:pending,processed',
        'filename' => 'required'
    ];
    public static $custom_validation_messages = [
        'in' => 'The selected :attribute is invalid. Option values (pending, processed).',
    ];

}
