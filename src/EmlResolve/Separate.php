<?php
namespace EmlResolve;

class Separate
{
    public $emlFile;

    public function __construct($emlFile)
    {
        $this->emlFile = $emlFile;
    }
    public static function NewInstance($emlFile)
    {
        return new self($emlFile);
    }
    /*分离eml文件头部*/
    public function analyzeHeader()
    {
        $headerStr = '';
        while (!feof($this->emlFile))
        {
            $emlFileStr = fgets($this->emlFile);
            /*判断hender是否结束*/
            if (ctype_cntrl($emlFileStr)) {
                break;
            }

            $headerStr .= $emlFileStr;
        }

        return $headerStr;
    }

    /**
     * 分离邮件分段标识符
     * @param $Headers
     * @return mixed
     */
    public function analyzeBoundary($Headers)
    {
        return $Headers->ParametersByName(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE)
            ->ParameterValueByName(\MailSo\Mime\Enumerations\Parameter::BOUNDARY);;
    }

    /**
     * 分离eml邮件体
     * @param string $boundary
     * @return array
     */
    public function analyzeBody(string $boundary)
    {
        $bodyStart = "--" . $boundary . "\r\n";
        $bodyEnd = "--" . $boundary . "--";
        $bodyContent = [];
        $bodyPartMark = 0;
        $startWrite = false;

        while (!feof($this->emlFile))
        {
            $fileStr = fgets($this->emlFile);

            if ($fileStr === $bodyEnd || !$fileStr) {
                break;
            }

            if ($fileStr === $bodyStart && count($bodyContent) === 0 && !$startWrite) {
                $startWrite = true;
                continue;
            } else if ($fileStr === $bodyStart && $startWrite) {
                $bodyPartMark++;
                continue;
            }else if (ctype_cntrl($fileStr) && !isset($bodyContent[$bodyPartMark])) {
                continue;
            }

            if ($startWrite && $fileStr !== $bodyStart && $fileStr !== $bodyEnd) {
                $bodyContent[$bodyPartMark] = empty($bodyContent[$bodyPartMark]) ? $fileStr : $bodyContent[$bodyPartMark] . $fileStr;
            }
        }

        return $bodyContent;
    }

    /*分离eml正文嵌套邮件体*/
    public function analyzeAlternativeBody(string $boundary, array $AlternativeBodyStr)
    {
        $bodyStart = "--" . $boundary . "\r\n";
        $bodyEnd = "--" . $boundary . "--";
        $bodyContent = [];
        $bodyPartMark = 0;
        $startWrite = false;

        foreach ($AlternativeBodyStr as $BodyStr) {
            if ($BodyStr === $bodyEnd) {
                break;
            }
            if ($BodyStr === $bodyStart && count($bodyContent) === 0 && !$startWrite) {
                $startWrite = true;
                continue;
            }else if ($BodyStr === $bodyStart && $startWrite) {
                $bodyPartMark++;
                continue;
            }

            if ($startWrite && $BodyStr !== $bodyStart && $BodyStr !== $bodyEnd) {
                $bodyContent[$bodyPartMark] = empty($bodyContent[$bodyPartMark]) ? $BodyStr : $bodyContent[$bodyPartMark] . $BodyStr;
            }
        }

        return $bodyContent;
    }

    /**
     * 分离正文头部
     * @param string $content
     * @return string[]
     */
    public function analyzeContentHeader(string $content)
    {
        $contentArr = explode("\r\n",$content);
        $segmentation = array_search('', $contentArr);

        return array_map(function ($value){
            return $value . "\r\n";
        }, array_slice($contentArr, 0, $segmentation));
    }

    /**
     * 分离邮件体正文
     * @param string $content
     * @return string[]
     */
    public function analyzeContentText(string $content)
    {
        $contentArr = explode("\r\n",$content);
        $segmentation = array_search('', $contentArr);

        return array_map(function ($value){
            return $value . "\r\n";
        }, array_slice($contentArr, $segmentation));
    }

    /*解析附件头部*/
    public function analyzeAttachHeader($attachHeader)
    {
        $attachName = $attachHeader->ParameterValue(\MailSo\Mime\Enumerations\Header::CONTENT_TYPE,
            \MailSo\Mime\Enumerations\Parameter::NAME);

        return [
            'attachName' => $attachName,
            'attachType' => pathinfo($attachName, PATHINFO_EXTENSION)
        ];
    }

    /**
     * 解析附件体
     * @param $attachContent
     */
    public function analyzeAttachContent($attachContent)
    {
        $mainBodyHtml = implode('', $attachContent);
        $mainBodyHtmlLength = strlen($mainBodyHtml);
        $mainBodyHtmlLength = intval( $mainBodyHtmlLength- ($mainBodyHtmlLength/8)*2);
        $mainBodyHtmlSize = number_format(($mainBodyHtmlLength/1024),2);
    }
}