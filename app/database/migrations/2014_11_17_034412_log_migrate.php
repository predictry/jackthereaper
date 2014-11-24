<?php

use Illuminate\Database\Migrations\Migration,
    Illuminate\Support\Facades\Schema;

class LogMigrate extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('log_migrate', function($table) {
            $table->increments('id');
            $table->string('name', 255)->after('id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('log_migrate');
    }

}
