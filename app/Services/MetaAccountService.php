<?php


namespace App\Services;



class MetaAccountService
{


    protected $graphApiVersion;

    protected $client;

    public function __construct()
    {
        $this->graphApiVersion = config('services.meta.graph_api_version');
        $this->client = new Client();
    }

    public function fetchUserInfo(){

    }

    public function fetchInstagramBusinessAccount(){

    }


    public function fetchInstagramAccountDetails(){

    }

}