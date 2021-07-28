<?php

namespace App\Controller;

use Imagick;
use Spatie\PdfToImage\Pdf;
use Aws\Textract\TextractClient;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\File;
use Spatie\PdfToImage\Exceptions\PdfDoesNotExist;
use Symfony\Component\DependencyInjection\Definition;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/* 
    * Accepte picture and return CSV format
*/

class ScanForCompta extends AbstractController
{
    private $_dataBruteTable;

    public function __invoke(Request $request)
    {

        $pdf = $request->files->get("pdf");
        $key = $request->request->get("key");
        $secret = $request->request->get("secret");

        if (!empty($pdf)&&!empty($key)&&!empty($secret)){

            $client = $this->credential($key, $secret);

            /*
                *analyse png and take Datajson from api AWS 
            */

            $path = dirname(__DIR__)."\\tmp\\";
            $picsName =  $this->convertPdfToPicture($pdf);
            $finaleResultes = [];
            
            foreach ($picsName as $picPath) {
                $blocks = $this->processingForAnalyseDocument($path.$picPath, $client);
                $finaleResultes [] = array_merge($this->dataTableReformatedToArrayAssoc($blocks)); 
                /* Aws dont like spam request , so we should do a stop */
                sleep(1.5);
            }
            
        
            // Send response Text
            $response = new Response();
            $response->setContent(\json_encode([
                $finaleResultes
                    ])
                );

            $response->headers->set('Content-Type', 'application/json');
            echo $response;

            $this->removePicAfterAnalyse($picsName,$path);
           
        }else{
           
            $response = new Response();
            $response->setContent(\json_encode([

                "erreur"=>" Les données: image,key où secret sont peut-étre vides, vérifiez les données envoyées"
            ]));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
        } 
       
    }

    private function credential($key, $secret)
    {
        $client = new TextractClient([
            'region' => 'eu-west-3',
            'version' => 'latest',
            'credentials' => [
                'key'    => $key,
                'secret' => $secret,
            ]
        ]);

        return $client;
    }

    private function processingForAnalyseDocument($pic, $client)
    {   
        
        $fileName = $pic;
        $file = fopen($fileName, "rb");
        $contents = fread($file, filesize($fileName));
        fclose($file);
        $options = [
            'Document' => [
                'Bytes' => $contents
            ],
            'FeatureTypes' => ['TABLES'],
        ];

        $result = $client->analyzeDocument($options);
        return $result['Blocks'];
    }
    private function findValueBlock($keyBlock, $valueMap)
    {
        foreach ($keyBlock['Relationships'] as $relationship) {
            if ($relationship['Type'] == 'VALUE') {
                foreach ($relationship['Ids'] as $valueId) {
                    $valueBlock = $valueMap[$valueId];
                }
            }
            return $valueBlock;
        }
    }
    
    private function dataTableReformatedToArrayAssoc($blocks)
    {
        $blocksMap = [];
        $tableBlocks = [];
        foreach ($blocks as $block) {
            $blocksMap[$block['Id']] = $block;
            if ($block['BlockType'] == "TABLE") {
                array_push($tableBlocks, $block);
            }
        }
        if (count($tableBlocks) <= 0) {
            return "<b> NO Table FOUND </b>";
        }
        $dataReformatedToArrayAssoc = [];
    
         foreach ($tableBlocks as $key => $table) {
           $dataReformatedToArrayAssoc = array_merge($dataReformatedToArrayAssoc,$this->reformateDataTableToJson($table, $blocksMap, $key + 1));
        }

        /* /^\d+([,.]\d{1,4})?/gm
        /\p{Sc}/g */

        return $dataReformatedToArrayAssoc;    
    }

     private function reformateDataTableToJson($tableResult, $blockMap, $tableIndex)
    {
        $rows = $this->getRowsColumnsMap($tableResult, $blockMap);
       
        $tableHeader = [];
        $tableKeyValue = [];
        $resultJsonArray =[];
       
        foreach ($rows as $key => $row) {
            $jsonReformateValue = [];         

            /* if found keyValue tab we should split and put in another array */
            if (count($row) == 2) {
               $tableKeyValue[] = [$row[1] =>$row[2]];
            }else {
                if ($key == 1) {
                    foreach ($row as $col) {
                        $tableHeader[] = $col;
                    }
                }
                if ($key > 1) {
                    for ($i=0; $i < count($row) ; $i++) { 
                        $jsonReformateValue[$tableHeader[$i]] = $row[$i+1];
                    }                    
                
                $resultJsonArray[]= $jsonReformateValue;
                }
            }
        }

    return $resultJsonArray;
    }

    private function getRowsColumnsMap($tableResult, $blocksMap)
    {
        $rows = [];
        foreach ($tableResult['Relationships'] as $relationship) {
            if ($relationship['Type'] == 'CHILD') {
                foreach ($relationship['Ids'] as $childId) {
                    $cell = $blocksMap[$childId];
                    if ($cell['BlockType'] == 'CELL') {

                        $rowIndex = $cell['RowIndex'];
                        $colIndex = $cell['ColumnIndex'];

                        if (array_key_exists($rowIndex, $rows) == false) {
                            $rows[$rowIndex] = [];
                        }
                        $cellValue = $this->getTextCsv($cell, $blocksMap);
                        $rows[$rowIndex][$colIndex] = $cellValue;
                    }
                }
            }
        }
        $this->_dataBruteTable = $rows;

        return $rows;
    }

 

    public function convertPdfToPicture($pdfData){
        $path = dirname(__DIR__)."\\tmp\\";
        $pdf = new Pdf($pdfData);
        $picsName = [];
            foreach (range(1, $pdf->getNumberOfPages()) as $pageNumber) {
                $pdf->setPage($pageNumber);
                $randomeName = uniqid().".png";
                $pdf->saveImage($path.'/'.$randomeName);  
                $picsName [] = $randomeName;
            }
        
        return $picsName;
    }

   function removePicAfterAnalyse($picsName,$path){
        foreach ($picsName as $picName) {
           unlink($path.$picName);        
        }
   }
