<?php

namespace test\Tests;

use Tokenly\APIClient\Exception\APIException;
use Tokenly\BvamApiClient\BVAMClient;
use PHPUnit_Framework_TestCase;

class BVAMClientTest extends \PHPUnit_Framework_TestCase
{
    public $testBvamProviders = [
        'https://bvamm.tokenly.com/',
        'https://bvam-stage.tokenly.com',
        'https://bvam.tokenly.com/invalid-url',
        "https://bvam.tokenly.com"
    ];
    public $bvamClient;

    function __construct()
    {
        $this->bvamClient = new BVAMClient($this->testBvamProviders);
    }

    function testBvamClientCreation()
    {

        //ensure that the class instantiates
        $this->assertInstanceOf("Tokenly\BvamApiClient\BVAMClient",
            $this->bvamClient);
        //The test providers array contains 2 mock offline endpoints
        //Ensure we only have 2 available endpoints to use.
        $this->assertCount(2, $this->bvamClient->availableApiBaseUrls,
            "incorrect number of available providers.");
    }

    function testgetAssetSuccess()
    {
        $getAssetInfoReturn = $this->bvamClient->getAssetInfo('SOUP');
        $this->assertNotNull($getAssetInfoReturn['hash'],
            "The asset returned with a null hash which means the provider it was fetched from did not give a complete response");
        $getAssetReturn = $this->bvamClient->getAsset($getAssetInfoReturn['bvamString'],
            $getAssetInfoReturn['hash']);
        $this->assertEquals($getAssetInfoReturn, $getAssetReturn);
    }

    function testgetAssetFailure()
    {
        $this->setExpectedException(APIException::class);
        $this->bvamClient->getAssetInfo('SOUPTest');
        $this->bvamClient->getAsset("{test:'test'}",
            "T2JAC8ix9g6PhsmKbeiXjtd2yEfCZ");
    }

    function testgetMultipleAssetsSuccess()
    {
        $getMultipleAssetsInfoReturn = $this->bvamClient->getMultipleAssetsInfo([
            "A229152867617021630",
            "SOUP"
        ]);
        $this->assertCount(2, $getMultipleAssetsInfoReturn);
        $this->assertNotNull($getMultipleAssetsInfoReturn["A229152867617021630"]['hash']);
        $this->assertNotNull($getMultipleAssetsInfoReturn["SOUP"]['hash']);
    }

    function testBvamProviderFallback()
    {
        $testAsset = $this->bvamClient->getAssetInfo("A229152867617021630");
        $this->assertContains("https://bvam.tokenly.com", $testAsset['uri']);
    }

    function testReturnHashFromUrlOrFilename()
    {
        $testUrl = "https://bvam.tokenly.com/TtR3AidBhf6pCxKP1jPTaJXGaCay.json";
        $testFilename = "TtR3AidBhf6pCxKP1jPTaJXGaCay.json";
        $testFailureString = "Non validating Test string";
        $this->assertEquals("TtR3AidBhf6pCxKP1jPTaJXGaCay",$this->bvamClient->returnBvamHashFromUrlOrFilename($testUrl));
        $this->assertEquals("TtR3AidBhf6pCxKP1jPTaJXGaCay",$this->bvamClient->returnBvamHashFromUrlOrFilename($testFilename));
        $this->assertFalse($this->bvamClient->returnBvamHashFromUrlOrFilename($testFailureString));
    }
}
