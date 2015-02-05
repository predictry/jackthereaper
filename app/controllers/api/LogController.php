<?php

namespace App\Controllers\Api;

use App\Models\LogMigration2,
    BaseController,
    Illuminate\Support\Facades\Input,
    Illuminate\Support\Facades\Response,
    Illuminate\Support\Facades\Validator;

class LogController extends BaseController
{

    /**
     * Update the specified resource in storage.
     *
     * @param  string $filename
     * @return Response
     */
    public function update($filename)
    {
        $inputs['status']   = Input::get('status');
        $inputs['filename'] = $filename;

        $validator = Validator::make($inputs, LogMigration2::$rules, LogMigration2::$custom_validation_messages);
        if ($validator->passes()) {

            $log_migration = LogMigration2::where('log_name', $filename)->first();

            if ($log_migration) {
                $log_migration->status = $inputs['status'];
                $log_migration->update();

                return Response::json(['error' => false, 'message' => 'Log status has been successfully updated.', 'status' => 200]);
            }
            else
                return Response::json(['error' => false, 'message' => "Couldn't find the log in the db, might be haven't push to the queue. Contact rifki@predictry.com."]);
        }
        else
            return Response::json(['error' => true, 'message' => $validator->errors()->first()]);
    }

}
