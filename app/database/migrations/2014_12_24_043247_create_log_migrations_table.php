<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateLogMigrationsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('log_migrations', function(Blueprint $table) {
            $table->increments('id');
            $table->string('log_name', 100);
            $table->integer('batch');
            $table->integer('total_logs');
            $table->integer('failed_executed_logs');
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
        Schema::drop('log_migrations');
    }

}
