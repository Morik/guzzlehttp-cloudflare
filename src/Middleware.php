<?php

namespace GuzzleCloudflare;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use CloudflareBypass\CFCurlImpl;
use CloudflareBypass\Model\UAMOptions;

class Middleware
{
    /** @var string USER_AGENT */
    const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_1) AppleWebKit/537.36 ' .
    '(KHTML, like Gecko) Chrome/70.0.3538.110 Safari/537.36';


    /** @var callable $cNextHandler */
    private $cNextHandler;
    /** @var array $aOptions */
    private $aOptions = [];

    /**
     * Middleware constructor.
     * @param callable $cNextHandler
     */
    public function __construct(callable $cNextHandler, array $aOptions = [])
    {
        $this->cNextHandler = $cNextHandler;
        $this->aOptions = $aOptions;
    }


    public static function create(array $aOptions = []): \Closure
    {
        return function ($cHandler) use ($aOptions) {
            return new static($cHandler, $aOptions);
        };
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $oRequest
     * @param array $aOptions
     *
     *
     * @return \Psr\Http\Message\RequestInterface
     */
    public function __invoke(RequestInterface $oRequest, array $aOptions = [])
    {
        $cNext = $this->cNextHandler;

        return $cNext($oRequest, $aOptions)
            ->then(
                function (ResponseInterface $oResponse) use ($oRequest, $aOptions) {
                    return $this->checkResponse($oRequest, $oResponse, $aOptions);
                }
            );
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $oRequest
     * @param \Psr\Http\Message\ResponseInterface $oResponse
     * @param array $aOptions
     *
     * @return \Psr\Http\Message\RequestInterface|\Psr\Http\Message\ResponseInterface
     * @throws \Exception
     */
    protected function checkResponse(RequestInterface $oRequest, ResponseInterface $oResponse, array $aOptions = [])
    {
        return !$this->shouldHack($oResponse) ? $oResponse : $this($this->hackRequest($oRequest), $aOptions);
    }

    /**
     * @param \Psr\Http\Message\ResponseInterface $oResponse
     *
     * @return bool
     */
    protected function shouldHack(ResponseInterface $oResponse)
    {
        return $oResponse->getStatusCode() === 503 &&
            strpos($oResponse->getHeaderLine('Server'), 'cloudflare') !== false;
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $oRequest
     *
     * @return \Psr\Http\Message\RequestInterface
     * @throws \Exception
     */
    protected function hackRequest(RequestInterface $oRequest): RequestInterface
    {
        $sUrl = $oRequest->getUri();
        $oCurlInstance = \curl_init($sUrl);
        \curl_setopt($oCurlInstance, \CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($oCurlInstance, \CURLOPT_USERAGENT, ($oRequest->getHeader('User-Agent')[0] ?? self::USER_AGENT));
        $cfCurl = new CFCurlImpl();
        $cfOptions = new UAMOptions();
        $cfCurl->exec($oCurlInstance, $cfOptions);
        $aCookies = curl_getinfo($oCurlInstance, \CURLINFO_COOKIELIST);
        $aSavedCookies = [];
        foreach ($aCookies as $sCookieLine) {
            $aSavedCookies[] = implode('=', array_slice(explode("\t", $sCookieLine), 5, 2));
        }
        return $oRequest->withHeader(
            'Cookie',
            array_merge($aSavedCookies, $oRequest->getHeader('Cookie'))
        );
    }
}
