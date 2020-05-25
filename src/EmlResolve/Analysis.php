<?php
namespace EmlResolve;

use EmlResolve\Separate;
class Analysis
{
    public $emlFile;

    public $emlHeader;
    public $emlBody;
    public $sHtml;
    public $sPlain;
    /**
     * @var array
     */
    public $emlBodyHtml;
    /**
     * @var array
     */
    public $emlBodyPlain;

    /**
     * @access protected
     * @param $emlFile
     */
    public function __construct($emlFile)
    {
        $this->emlFile = @fopen($emlFile, "r");
    }

    public function mailAnalyze($sCharset = '')
    {
        $emlAnalysis = Separate::NewInstance($this->emlFile);
        $sRawHeaders = $emlAnalysis->analyzeHeader();

        if (0 >= \strlen($sRawHeaders))
        {
            throw new Exception('The file format is incorrect!');
        }

        $oHeaders = \MailSo\Mime\HeaderCollection::NewInstance()->Parse($sRawHeaders, false);

        $sContentTypeCharset = $oHeaders->ParameterValue(
            \MailSo\Mime\Enumerations\Header::CONTENT_TYPE,
            \MailSo\Mime\Enumerations\Parameter::CHARSET
        );

        if (0 < \strlen($sContentTypeCharset))
        {
            $sCharset = $sContentTypeCharset;
            $sCharset = \MailSo\Base\Utils::NormalizeCharset($sCharset);
        }

        if (0 < \strlen($sCharset))
        {
            $oHeaders->SetParentCharset($sCharset);
        }

        $bCharsetAutoDetect = 0 === \strlen($sCharset);

        $boundary = $emlAnalysis->analyzeBoundary($oHeaders);
        $body = $emlAnalysis->analyzeBody($boundary);

        $this->emlHeader = $oHeaders;
        $this->emlBody = $body;
        $this->sSubject = $oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::SUBJECT, $bCharsetAutoDetect);
        $this->sMessageId = $oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::MESSAGE_ID);
        $this->sContentType = $oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE);
        $this->oFrom = $oHeaders->GetAsEmailCollection(\MailSo\Mime\Enumerations\Header::FROM_, $bCharsetAutoDetect);
        $this->oTo = $oHeaders->GetAsEmailCollection(\MailSo\Mime\Enumerations\Header::TO_, $bCharsetAutoDetect);
        $this->oCc = $oHeaders->GetAsEmailCollection(\MailSo\Mime\Enumerations\Header::CC, $bCharsetAutoDetect);
        $this->oBcc = $oHeaders->GetAsEmailCollection(\MailSo\Mime\Enumerations\Header::BCC, $bCharsetAutoDetect);
        $oHeaders->PopulateEmailColectionByDkim($this->oFrom);
        $this->oSender = $oHeaders->GetAsEmailCollection(\MailSo\Mime\Enumerations\Header::SENDER, $bCharsetAutoDetect);
        $this->oReplyTo = $oHeaders->GetAsEmailCollection(\MailSo\Mime\Enumerations\Header::REPLY_TO, $bCharsetAutoDetect);
        $this->oDeliveredTo = $oHeaders->GetAsEmailCollection(\MailSo\Mime\Enumerations\Header::DELIVERED_TO, $bCharsetAutoDetect);
        $this->sInReplyTo = $oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::IN_REPLY_TO);
        $this->sReferences = \MailSo\Base\Utils::StripSpaces($oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::REFERENCES));
        $sHeaderDate = $oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::DATE);
        $this->sHeaderDate = $sHeaderDate;
        $this->iHeaderTimeStampInUTC = \MailSo\Base\DateTimeHelper::ParseRFC2822DateString($sHeaderDate);
        // Sensitivity
        $this->iSensitivity = \MailSo\Mime\Enumerations\Sensitivity::NOTHING;
        $sSensitivity = $oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::SENSITIVITY);
        switch (\strtolower($sSensitivity))
        {
            case 'personal':
                $this->iSensitivity = \MailSo\Mime\Enumerations\Sensitivity::PERSONAL;
                break;
            case 'private':
                $this->iSensitivity = \MailSo\Mime\Enumerations\Sensitivity::PRIVATE_;
                break;
            case 'company-confidential':
                $this->iSensitivity = \MailSo\Mime\Enumerations\Sensitivity::CONFIDENTIAL;
                break;
        }
        // Priority
        $this->iPriority = \MailSo\Mime\Enumerations\MessagePriority::NORMAL;
        $sPriority = $oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::X_MSMAIL_PRIORITY);
        if (0 === \strlen($sPriority))
        {
            $sPriority = $oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::IMPORTANCE);
        }
        if (0 === \strlen($sPriority))
        {
            $sPriority = $oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::X_PRIORITY);
        }
        if (0 < \strlen($sPriority))
        {
            switch (\str_replace(' ', '', \strtolower($sPriority)))
            {
                case 'high':
                case '1(highest)':
                case '2(high)':
                case '1':
                case '2':
                    $this->iPriority = \MailSo\Mime\Enumerations\MessagePriority::HIGH;
                    break;

                case 'low':
                case '4(low)':
                case '5(lowest)':
                case '4':
                case '5':
                    $this->iPriority = \MailSo\Mime\Enumerations\MessagePriority::LOW;
                    break;
            }
        }
        // Delivery Receipt
        $this->sDeliveryReceipt = \trim($oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::RETURN_RECEIPT_TO));
        // Read Receipt
        $this->sReadReceipt = \trim($oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::DISPOSITION_NOTIFICATION_TO));
        if (empty($this->sReadReceipt))
        {
            $this->sReadReceipt = \trim($oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::X_CONFIRM_READING_TO));
        }
        //Unsubscribe links
        $this->aUnsubsribeLinks = $oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::LIST_UNSUBSCRIBE);
        if (empty($this->aUnsubsribeLinks))
        {
            $this->aUnsubsribeLinks = array();
        }
        else
        {
            $this->aUnsubsribeLinks = explode(',', $this->aUnsubsribeLinks);
            $this->aUnsubsribeLinks = array_map(
                function ($link) {
                    return trim($link, ' <>');
                },
                $this->aUnsubsribeLinks
            );
        }
        $sDraftInfo = $oHeaders->ValueByName(\MailSo\Mime\Enumerations\Header::X_DRAFT_INFO);
        if (0 < \strlen($sDraftInfo))
        {
            $sType = '';
            $sFolder = '';
            $sUid = '';

            \MailSo\Mime\ParameterCollection::NewInstance($sDraftInfo)
                ->ForeachList(function ($oParameter) use (&$sType, &$sFolder, &$sUid) {

                    switch (\strtolower($oParameter->Name()))
                    {
                        case 'type':
                            $sType = $oParameter->Value();
                            break;
                        case 'uid':
                            $sUid = $oParameter->Value();
                            break;
                        case 'folder':
                            $sFolder = \base64_decode($oParameter->Value());
                            break;
                    }
                })
            ;

            if (0 < \strlen($sType) && 0 < \strlen($sFolder) && 0 < \strlen($sUid))
            {
                $this->aDraftInfo = array($sType, $sUid, $sFolder);
            }
        }

        $this->analyzeMainBody($this->emlBody, $emlAnalysis);

        if (!empty($this->emlBodyHtml)) {
            $this->analyzeHtmlOrPlain($this->emlBodyHtml, $sCharset);
        }

        if (!empty($this->emlBodyPlain)) {
            $this->analyzeHtmlOrPlain($this->emlBodyPlain, $sCharset);
        }

        return $this->responseJson();
    }

    /*获取邮件正文内容*/
    public function analyzeMainBody($emlBody, $emlAnalysisExamples)
    {
        foreach ($emlBody as $content) {
            $contentHeaderStr = $emlAnalysisExamples->analyzeContentHeader($content);
            $contentHeaderCollection = \MailSo\Mime\HeaderCollection::NewInstance()->Parse(implode('', $contentHeaderStr));
            $contentHtmlStr = $emlAnalysisExamples->analyzeContentText($content);
            $contentType = $this->getContentType($contentHeaderCollection);

            if ($contentType === \MailSo\Mime\Enumerations\MimeType::TEXT_HTML) {
                $this->emlBodyHtml = [
                    'mainBodyHeader' => $contentHeaderCollection,
                    'mainBodyHtml' => implode('', $contentHtmlStr)
                ];

            } elseif ($contentType === \MailSo\Mime\Enumerations\MimeType::TEXT_PLAIN) {
                $this->emlBodyPlain = [
                    'mainBodyHeader' => $contentHeaderCollection,
                    'mainBodyHtml' => implode('', $contentHtmlStr)
                ];
            } elseif ($contentType === \MailSo\Mime\Enumerations\MimeType::MULTIPART_ALTERNATIVE){
                $contentAlternativeBoundary = $emlAnalysisExamples->analyzeBoundary($contentHeaderCollection);
                $contentAlternativeBody = $emlAnalysisExamples->analyzeAlternativeBody($contentAlternativeBoundary, $contentHtmlStr);
                $this->analyzeMainBody($contentAlternativeBody);
            } else {
                $this->emlBodyAttach[] = $emlAnalysisExamples->analyzeAttachHeader($contentHeaderCollection);
            }

        }
    }


    /*获取正文类型*/
    public function getContentType($headerCollection)
    {
        return $headerCollection->ValueByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE);
    }

    /*解析正文*/
    public function analyzeHtmlOrPlain($mainBody, $sCharset = '')
    {
        if (\is_array($mainBody) && 0 < \count($mainBody))
        {
            if (0 === \strlen($sCharset))
            {
                $sCharset = \MailSo\Base\Enumerations\Charset::UTF_8;
            }

            $aHtmlParts = array();
            $aPlainParts = array();


            $sText = $mainBody['mainBodyHtml'];

            if (\is_string($sText) && 0 < \strlen($sText))
            {
                $sTextCharset = $mainBody['mainBodyHeader']->ParameterValue(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE,
                    \MailSo\Mime\Enumerations\Parameter::CHARSET);
                if (empty($sTextCharset))
                {
                    $sTextCharset = $sCharset;
                }

                if (strpos($sTextCharset, ',')) {
                    $sTextCharset = array_map(function ($value){
                        return trim($value);
                    }, explode(',', $sTextCharset));

                    $sTextCharset = end($sTextCharset);
                }

                $sText = \MailSo\Base\Utils::DecodeEncodingValue($sText, $mainBody['mainBodyHeader']->ValueByName(
                    \MailSo\Mime\Enumerations\Header::CONTENT_TRANSFER_ENCODING
                ));
                $sText = \MailSo\Base\Utils::ConvertEncoding($sText, $sTextCharset, \MailSo\Base\Enumerations\Charset::UTF_8);
                $sText = \MailSo\Base\Utils::Utf8Clear($sText);

                if ('text/html' === $mainBody['mainBodyHeader']->ValueByName(
                        \MailSo\Mime\Enumerations\Header::CONTENT_TYPE
                    ))
                {
                    $aHtmlParts[] = $sText;
                }
                else
                {
                    $aPlainParts[] = $sText;
                }
            }

            if (0 < \count($aHtmlParts))
            {
                $this->sHtml = \implode('<br />', $aHtmlParts);
            }
            else
            {
                $this->sPlain = \trim(\implode("\n", $aPlainParts));
            }

            unset($aHtmlParts, $aPlainParts);
        }
    }
    protected function responseJson()
    {
        return json_encode([
            'Subject' => $this->sSubject,
            'To' => $this->oTo->ToString(),
            'From' => $this->oFrom->ToString(),
            'Html' => $this->sHtml,
            'Plain' => $this->sPlain,
            'Attach' => $this->emlBodyAttach,
        ]);
    }
}