<?php

/*
  |--------------------------------------------------------------------------
  | Register The Artisan Commands
  |--------------------------------------------------------------------------
  |
  | Each available Artisan command must be registered with the console so
  | that it is available to be called. We'll register every command so
  | the console gets access to each of the command object instances.
  |
 */

//Artisan::add(new HarvestActions);
Artisan::add(new HarvestLogs);
Artisan::add(new CheckLogs());
//Artisan::add(new CheckProcessed());
//Artisan::add(new ReverseBackupLogs());
Artisan::add(new ParseLogsIntoJSON());
Artisan::add(new CheckLogsFile());