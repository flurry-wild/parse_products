<?php

namespace App\Console\Commands;

use App\Services\OzonParser;
use Illuminate\Console\Command;
use App\Exceptions\OzonParserException;
use App\Exceptions\OzonForbiddenException;

class OzonParse extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ozon:parse';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * @param OzonParser $ozonParser
     *
     * @return void
     *
     * @throws OzonParserException
     * @throws OzonForbiddenException
     */
    public function handle(OzonParser $ozonParser)
    {
        $ozonParser->start();
    }
}
