<?php

namespace Tokenly\BvamApiClient;

use Exception;
use Illuminate\Http\Request;
use SebastianBergmann\CodeCoverage\Driver\Xdebug;
use Tokenly\APIClient\Exception\APIException;
use Tokenly\APIClient\TokenlyAPI;
use StephenHill;
use Requests;

class BVAMClient extends TokenlyAPI
{
    public $availableApiBaseUrls = [];
    public $activeApiBaseIndex = 0;

    function __construct($api_base_urls = null)
    {
        if ( ! $api_base_urls) {
            $availableUrls[] = 'https://bvam.tokenly.com';
        } else {
            $availableUrls = $api_base_urls;
        }
        $this->determineActiveBvamProvider($availableUrls);
        parent::__construct($this->availableApiBaseUrls[$this->activeApiBaseIndex]);
    }

    public function getBvamList()
    {
        return $this->getPublic('api/v1/bvam/all');
    }

    public function getCategoryList()
    {
        return $this->getPublic('api/v1/category/all');
    }

    /**
     * This method will accept the raw json bvam string and a hash and determine
     * the validity of the string with provided hash. If the bvam string is
     * validated we will return the bvam data.
     *
     * @param $bvamJson
     * @param $hash
     *
     * @return mixed
     * @throws \Tokenly\APIClient\Exception\APIException
     */
    public function getAsset($bvamJson, $hash)
    {
        if ($this->createBvamHash($bvamJson) === $hash) {
            $bvamData = json_decode($bvamJson);

            return $this->getAssetInfo($bvamData->asset);
        } else {
            throw new APIException("Cannot validate asset");
        }

    }

    public function getAssetInfo($asset_name)
    {
        $asset = $this->getPublic('api/v1/asset/' . $asset_name);
        if ( ! $this->isValidAsset($asset)) {
            return $this->getAssetInfo($asset_name);
        } else {
            return $asset;
        }
    }

    public function getMultipleAssetsInfo($asset_names)
    {
        $get = $this->getPublic('api/v1/assets',
            ['assets' => implode(',', $asset_names)]);
        if ($get) {
            //use asset names as array keys
            $output = array();
            foreach ($get as $asset) {
                /*
                 * For each asset that we return we need to check its validity
                 * and if it is not valid then we will continue to check
                 * providers for a valid asset.
                 */
                if ( ! $this->isValidAsset($asset)) {
                    $output[$asset['asset']] = $this->getAssetInfo($asset['asset']);
                } else {
                    $output[$asset['asset']] = $asset;
                }
            }

            return $output;
        }
    }

    public function addBvamJson($bvam_json)
    {
        return $this->postPublic('api/v1/bvam', ['bvam' => $bvam_json]);
    }

    public function addCategoryJson($category_json)
    {
        return $this->postPublic('api/v1/category',
            ['category' => $category_json]);
    }

    /**
     * This method is meant to check whether or not an asset is valid as
     * determined by 2 factors.
     *  1: Whether or not the asset actually exist on the provider endpoint.
     *  2: If the BVAM hash is valid when recomputed from the raw BVAM string.
     *
     *  If the asset does not exist on the endpoint then this method will retry
     *  the call using the next BVAM provider in the list supplied at class
     *  instantiation.
     *
     * @param $asset
     *
     * @return bool
     */
    protected function isValidAsset($asset)
    {
        if ( ! $asset['bvamString']) {
            return $this->moveToNextProviderUrl($asset);
        } else {
            /*
             * If the BVAM string is present then we need to validate the hash
             * of the asset against the BVAM string. If the asset is valid then
             * we return true otherwise we move to the next provider in our list
             * and try again.
             */
            return ($this->isValidBvam($asset) ? true : $this->moveToNextProviderUrl($asset));
        }
    }

    /**
     * In order to determine that an asset is valid we first compute the hash
     * from the raw BVAM string using the methodology outlined in CIP-7. Then
     * we compare the resulting hash to the hash that was returned by the BVAM
     * provider. If these hashes match then the data is valid if not then we
     * return false.
     * CIP-7 Doc:https://github.com/CounterpartyXCP/cips/blob/master/cip-0007.md
     *
     * @param $bvamData
     *
     * @return bool
     */
    public function isValidBvam($bvamData)
    {
        $computedHash = $this->createBvamHash($bvamData['bvamString']);

        return $computedHash === $bvamData['hash'];
    }

    /**
     * This method accepts raw BVAM Json and returns a computed BVAM hash using
     * CIP-7 methodology.
     *
     * @param $bvamJson
     *
     * @return string
     */
    public function createBvamHash($bvamJson)
    {
        $hash      = hash('ripemd160', hash('sha256', $bvamJson, true), true);
        $base58Lib = new StephenHill\Base58();

        return 'T' . $base58Lib->encode($hash);
    }

    /**
     * Currently if we try and pass a url that doesnt exist into the list of
     * available BVM providers then we throw an exception. In the event that
     * only some of the providers are offline we dont want that to stop us from
     * continuing on using the providers that are online. This method iterates
     * through the list of providers provided on class instantiation and ensures
     * that the final list of available providers only includes providers that
     * are online.
     *
     * @param $availableUrls
     */
    protected function determineActiveBvamProvider($availableUrls)
    {
        foreach ($availableUrls as $key => $bvamProviderUrl) {

            $headers = @get_headers($bvamProviderUrl, 1);
            if ($headers[0] === "HTTP/1.1 200 OK") {
                $this->availableApiBaseUrls[] = $bvamProviderUrl;
            }

        }
    }

    /**
     * In the event that we fail to validate an asset from a given provider we
     * need to try again with the next provider in our list. In the event we
     * try all of the providers and none validate the asset we will return an
     * exception.
     *
     * @param $asset
     *
     * @return bool
     * @throws \Tokenly\APIClient\Exception\APIException
     */
    private function moveToNextProviderUrl($asset)
    {
        /*
             * If the bvamString is not set then the asset does not exist on
             * this endpoint and we must try the next
             */
        if ($this->activeApiBaseIndex !== (count($this->availableApiBaseUrls) - 1)) {
            $this->activeApiBaseIndex++;
            parent::__construct($this->availableApiBaseUrls[$this->activeApiBaseIndex]);

            return false;
        } else {
            /*
             * If we run out of BVAM provider urls to try and we still have
             * not found a valid asset then we throw an exception.
             */
            throw new APIException(sprintf("Cannot find valid asset of name %s",
                $asset['asset']));
        }
    }

    /**
     * This method will accept the filename of a asset or the url of an asset
     * and return the hash for that asset.
     * @param $string
     *
     * @return bool
     */
    public function returnBvamHashFromUrlOrFilename($string)
    {
        preg_match("([T|C]{1}[1-9A-HJ-NP-Za-hi-z]{20,}+)", $string, $matches);
        if (count($matches) > 0) {
            return $matches[0];
        } else {
            return false;
        }
    }

}
