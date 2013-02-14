<?php
/**
Copyright (C) 2012 Michel Dumontier

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
of the Software, and to permit persons to whom the Software is furnished to do
so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/

/**
 * BioPAX RDFizer
 * @version 1.0
 * @author Michel Dumontier
 * @description 
*/
require('../../php-lib/rdfapi.php');
class BioPAXParser extends RDFFactory 
{		
	function __construct($argv) {
		parent::__construct();
		
		// set and print application parameters
		$this->AddParameter('files',true,null,'all','entries to process: comma-separated list or hyphen-separated range');
		$this->AddParameter('indir',false,null,'/tmp/biopax/download/','directory to download into and parse from');
        $this->AddParameter('outdir',false,null,'/tmp/biopax/data/','directory to place rdfized files');
        $this->AddParameter('download',false,'true|false','download remote file to indirectory');
        $this->AddParameter('download_url',false,null,'http://pathway-commons.googlecode.com/files/pathwaycommons2-Sept2012.owl.zip');
		$this->AddParameter('gzip',false,'true|false','true','gzip the output');
		if($this->SetParameters($argv) == FALSE) {
			$this->PrintParameters($argv);
			exit;
		}
		if($this->CreateDirectory($this->GetParameterValue('indir')) === FALSE) exit;
		if($this->CreateDirectory($this->GetParameterValue('outdir')) === FALSE) exit;
		
		return TRUE;
	}
	
	function Run()
	{	
        $indir  = $this->GetParameterValue('indir');
        $outdir = $this->GetParameterValue('outdir');
                
        $file = $indir."pathwaycommons.owl.zip";
        $outfile = $outdir."pathwaycommons_map.nt.gz";
        
        preg_match('/.*(pathwaycommons[A-Za-z0-9-]+.owl)/',$this->GetParameterValue('download_url'),$filematch);
         echo $unzipped_file;
        // Download
        if ($this->GetParameterValue('download')){
            $download = $this->GetParameterValue('download');
            echo "INFO: Download file from ".$download;
            file_put_contents($file,file_get_contents($download));
        }

        echo "INFO: Unzipping ".$file."\n";
        $zip = new ZipArchive;
        if ($zip->open($file) === TRUE) {
            $zip->extractTo($indir);
            $zip->close();
        } else {
            echo 'failed';
        }
        echo "INFO: Unzipped pathway commons file\n";

        $this->SetWriteFile($outfile,TRUE);
        echo "INFO: Setting outfile to ".$outfile."\n";

        // Convert to ntriples
        // $cmd = "rapper ".$file." > ".$indir."pathwaycommons.nt";
        // exec($cmd);

        // Generate links mapping identifiers.org to bio2rdf.org
        // this would be so much easier with sed :) 
       
        // match pubmed identifiers
        // http://identifiers.org/pubmed/11447118
        $pubmed = "/.*pubmed\/(\d+)\"/";

        // taxonomy
        // http://identifiers.org/taxonomy/9606
        $taxon = "/http:.*taxonomy\/(\d+)/";
        
        // uniprot
        // http://identifiers.org/uniprot/Q9R1Z8
        $uniprot = "/uniprot\/[A-Za-z0-9]+\"/";
       
        $tmp_file = $outdir."biopax_mappings.nt";
        $th = fopen($unzipped_file,'w') or die("ERROR: Unable to open temp file");
        $handle = fopen($indir."pathwaycommons2-Sept2012.owl","r");
        if ($handle) {
            while (($buffer = fgets($handle, 4096)) != false){
                preg_match($taxon,$buffer,$taxon_matches);
                if ($taxon_matches[1]){
                    $this->AddRDF($this->QQuadO_URL("taxon:".$taxon_matches[1],"owl:sameAs",$taxon_matches[0]));
                }
                // echo $buffer


                $this->WriteRDFBufferToWriteFile();
            }

            fclose($handle);
        }else{
            echo "ERROR: Unable to open file";
            exit();
        }

        // output triples
	}

}


set_error_handler('error_handler');
$parser = new BioPAXParser($argv);
$parser->Run();

	
	
