<?php

namespace App\Traits;

use QrCode;
use App\Tax;
use Storage;
use App\User;
use Exception;
use Mpdf\Mpdf;
use ZipArchive;
use App\Company;
use App\Document;
use App\DocumentPayroll;
use App\ReceivedDocument;
use DOMDocument;
use App\Customer;
use App\Employee;
use App\Resolution;
use App\TypeRegime;
use ubl21dian\Sign;
use App\TypeDocument;
use App\TypeLiability;
use App\TypeOperation;
use Mpdf\HTMLParserMode;
use App\Mail\InvoiceMail;
use App\Custom\zipfileDIAN;
use InvalidArgumentException;
use Mpdf\Config\FontVariables;
use Mpdf\Config\ConfigVariables;
use App\Mail\PasswordCustomerMail;
use App\Mail\PasswordEmployeeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use App\Http\Controllers\Api\ConfigurationController;
use App\Services\StorageService;
use App\Country;
use App\Municipality;
use DateTime;
use Carbon\Carbon;

/**
 * Document trait.
 */
trait DocumentTrait
{
    /**
     * PPP.
     *
     * @var string
     */
    public $ppp = '000';

    /**
     * Payment form default.
     *
     * @var array
     */
    private $paymentFormDefault = [
        'payment_form_id' => 1,
        'payment_method_id' => 10,
    ];

    protected function resolveCustomerLocation($customerAll)
    {
        $countryName      = isset($customerAll['country_name'])      ? trim($customerAll['country_name'])      : null;
        $municipalityName = isset($customerAll['municipality_name']) ? trim($customerAll['municipality_name']) : null;
        $stateName        = isset($customerAll['state_name'])        ? trim($customerAll['state_name'])        : null;

        if (!$countryName && !$municipalityName) {
            return $customerAll;
        }

        if ($countryName && empty($customerAll['country_id'])) {
            // fold(): en PostgreSQL LIKE distingue mayusculas y acentos
            // (MySQL no); el nombre viene del XML/JSON con formato libre.
            $country = Country::whereRaw(\App\Helpers\SqlDialect::fold('name') . ' LIKE ' . \App\Helpers\SqlDialect::fold('?'), ["%{$countryName}%"])->first();
            if ($country) {
                $customerAll['country_id'] = $country->id;
            }
        }

        $isColombia = ($customerAll->get('country_id') == 46)
                   || ($countryName && stripos($countryName, 'colombia') !== false);

        if ($isColombia && $municipalityName && empty($customerAll['municipality_id'])) {
            if ($stateName) {
                $fold = fn ($expr) => \App\Helpers\SqlDialect::fold($expr);
                $municipality = Municipality::join('departments', 'municipalities.department_id', '=', 'departments.id')
                    ->select('municipalities.*')
                    ->whereRaw($fold('municipalities.name') . ' LIKE ' . $fold('?'), ["%{$municipalityName}%"])
                    ->whereRaw($fold('departments.name') . ' LIKE ' . $fold('?'), ["%{$stateName}%"])
                    ->orderBy('municipalities.name')
                    ->first();
            } else {
                $municipality = Municipality::whereRaw(\App\Helpers\SqlDialect::fold('name') . ' LIKE ' . \App\Helpers\SqlDialect::fold('?'), ["%{$municipalityName}%"])
                    ->orderBy('name')
                    ->first();
            }

            if ($municipality) {
                $customerAll['municipality_id'] = $municipality->id;
            }
        }

        return $customerAll;
    }

    /**
     * Get query.
     *
     * @param string $query
     * @param bool   $validate
     * @param int    $item
     *
     * @return mixed
     */
    protected function getQuery($document, $query, $validate = true, $item = 0)
    {
        if (is_string($document)){
            $xml = $document;
            $document = new \DOMDocument;
            $document->loadXML($xml);
            $domXPath = new \DOMXPath($document);
        }

        $tag = $domXPath->query($query);
        if (($validate) && (null == $tag->item(0))) {
            return null;
//            throw new Exception('Class '.get_class($this).": The query {$query} does not exist.");
        }
        if (is_null($item)) {
            return $tag;
        }

        return $tag->item($item);
    }

    protected function getTag($document, $tagName, $item = 0, $attribute = NULL, $attribute_value = NULL)
    {
        if (is_string($document)){
            $xml = $document;
            $document = new \DOMDocument;
            $document->loadXML($xml);
        }

        $tag = $document->documentElement->getElementsByTagName($tagName);

        if (is_null($tag->item(0))) {
            return;
        }

        if($attribute)
            if($attribute_value){
                $tag->item($item)->setAttribute($attribute, $attribute_value);
                return;
            }
            else
                return $tag->item($item)->getAttribute($attribute);
        else
            return $tag->item($item);
    }

    protected function registerEmployee($data, $sendmail = false)
    {
        $user = auth()->user();
        // Configura SMTP y remitente de la empresa (este flujo no usa smtp_parameters).
        $user->applyMailConfig();

        $password = "12345";
        $employee = Employee::where('identification_number', '=', $data->identification_number)->get();
        if(count($employee) == 0){
            $password = \Str::random(6);
            $data->password = bcrypt($password);
        }
        else
            $data->password = $employee[0]->password;
        $employee = Employee::updateOrCreate(['identification_number' => $data->identification_number],
                                             ['first_name' => $data->first_name,
                                              'middle_name' => $data->middle_name,
                                              'surname' => $data->surname,
                                              'second_surname' => $data->second_surname,
                                              'password' => $data->password,
                                              'address' => $data->address,
                                              'email' => $data->email
                                             ]);

        if($sendmail && $data->identification_number != '222222222222' && isset($data->email) && ($data->email != ''))
            if(\Carbon\Carbon::now()->format('Y-m-d H:i') === date_format(date_create($employee->created_at), 'Y-m-d H:i'))
                Mail::to($employee->email)->send(new PasswordEmployeeMail($employee, $password));
    }

    protected function registerCustomer($data, $sendmail = false, $sendingcustomer = false)
    {
        $user = auth()->user();
        // Configura SMTP y remitente de la empresa (este flujo no usa smtp_parameters).
        $user->applyMailConfig();

        $password = "12345";
        $companyId = $user->company->id;

        // Si existe un customer con el mismo NIT pero companies_id = NULL (registro previo a la migración),
        // asignarle el companies_id correcto antes de hacer updateOrCreate.
        // Forzar string: identification_number es VARCHAR y puede contener letras
        // (pasaportes, extranjeros). Si se bindea como int, MySQL convierte la columna
        // a DOUBLE y revienta con SQLSTATE 22007 al topar valores con letras.
        $identNumber = (string) ($sendingcustomer ? $data->identification_number : $data->company->identification_number);
        Customer::where('identification_number', $identNumber)
                ->whereNull('companies_id')
                ->update(['companies_id' => $companyId]);

        $customer = Customer::where('identification_number', '=', $identNumber)
                             ->where('companies_id', $companyId)->get();
        if(count($customer) == 0){
            $password = \Str::random(6);
            $data->password = bcrypt($password);
        }
        else
            $data->password = $customer[0]->password;
        if($sendingcustomer){
            if(array_key_exists('dv', $data->all()))
              $dv = $data->dv;
            else
              $dv = NULL;
            $customer = Customer::updateOrCreate(
                                                 ['identification_number' => $identNumber,
                                                  'companies_id' => $companyId],
                                                 ['dv' => $dv,
                                                  'name' => $data->name,
                                                  'phone' => $data->phone,
                                                  'password' => $data->password,
                                                  'address' => $data->address,
                                                  'email' => $data->email
                                                 ]);
            if($sendmail && $identNumber != '222222222222')
                if(\Carbon\Carbon::now()->format('Y-m-d H:i') === date_format(date_create($customer->created_at), 'Y-m-d H:i'))
                    Mail::to($customer->email)->send(new PasswordCustomerMail($customer, $password));
        }
        else{
            $customer = Customer::updateOrCreate(
                                                 ['identification_number' => $identNumber,
                                                  'companies_id' => $companyId],
                                                 ['dv' => $data->company->dv,
                                                  'name' => $data->name,
                                                  'phone' => $data->company->phone,
                                                  'password' => $data->password,
                                                  'address' => $data->company->address,
                                                  'email' => $data->email
                                                 ]);

            if($sendmail && $identNumber != '222222222222')
                if(\Carbon\Carbon::now()->format('Y-m-d H:i') === date_format(date_create($customer->created_at), 'Y-m-d H:i'))
                    Mail::to($customer->email)->send(new PasswordCustomerMail($customer, $password));
        }
    }

    /**
     * Create xml.
     *
     * @param array $data
     *
     * @return DOMDocument
     */
    protected function createXML(array $data)
    {
        if($data['typeDocument']['code'] === '01' or $data['typeDocument']['code'] === '02' or $data['typeDocument']['code'] === '03' or $data['typeDocument']['code'] === '05' or $data['typeDocument']['code'] === '95' or $data['typeDocument']['code'] === '91' or $data['typeDocument']['code'] === '92' or $data['typeDocument']['code'] === '20' or $data['typeDocument']['code'] === '35' or $data['typeDocument']['code'] === '25' or $data['typeDocument']['code'] === '60' or $data['typeDocument']['code'] === '93' or $data['typeDocument']['code'] === '94'){
            if($data['typeDocument']['code'] === '05' or $data['typeDocument']['code'] === '95'){
                if($data['company']['support_document_type_environment_id'] == 2)
                    $urlquery = 'https://catalogo-vpfe-hab.dian.gov.co';
                else
                    $urlquery = 'https://catalogo-vpfe.dian.gov.co';
            }
            else if($data['typeDocument']['code'] === '01' or $data['typeDocument']['code'] === '02' or $data['typeDocument']['code'] === '03' or $data['typeDocument']['code'] === '04' or $data['typeDocument']['code'] === '91' or $data['typeDocument']['code'] === '92'){
                if($data['company']['type_environment_id'] == 2)
                    $urlquery = 'https://catalogo-vpfe-hab.dian.gov.co';
                else
                    $urlquery = 'https://catalogo-vpfe.dian.gov.co';
            }
            else{
                if($data['typeDocument']['code'] === '20' or $data['typeDocument']['code'] === '35' or $data['typeDocument']['code'] === '25' or $data['typeDocument']['code'] === '60' or $data['typeDocument']['code'] === '93' or $data['typeDocument']['code'] === '94'){
                    if($data['company']['eqdocs_type_environment_id'] == 2)
                        $urlquery = 'https://catalogo-vpfe-hab.dian.gov.co';
                    else
                        $urlquery = 'https://catalogo-vpfe.dian.gov.co';
                }
            }

            $QRCode = $urlquery.'/document/searchqr?documentkey=-----CUFECUDE-----';
            \Log::debug($data['company']);
            \Log::debug($QRCode);

//            if($data['typeDocument']['code'] === '01' or $data['typeDocument']['code'] === '02' or $data['typeDocument']['code'] === '03' or $data['typeDocument']['code'] === '20' or $data['typeDocument']['code'] === '35' or $data['typeDocument']['code'] === '24')
//                if(isset($data['request']['tax_totals'][0]['tax_amount']))
//                    $QRCode = 'NumFac: '.$data['resolution']['next_consecutive'].PHP_EOL.'FecFac: '.$data['date'].PHP_EOL.'NitFac: '.$data['user']['company']['identification_number'].PHP_EOL.'DocAdq: '.$data['customer']['company']['identification_number'].PHP_EOL.'ValFac: '.$data['legalMonetaryTotals']['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$data['request']['tax_totals'][0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$data['legalMonetaryTotals']['allowance_total_amount'].PHP_EOL.'ValTotal: '.$data['legalMonetaryTotals']['payable_amount'].PHP_EOL.'CUFE: -----CUFECUDE-----'.PHP_EOL.$urlquery.'/document/searchqr?documentkey=-----CUFECUDE-----';
//                else
//                    $QRCode = 'NumFac: '.$data['resolution']['next_consecutive'].PHP_EOL.'FecFac: '.$data['date'].PHP_EOL.'NitFac: '.$data['user']['company']['identification_number'].PHP_EOL.'DocAdq: '.$data['customer']['company']['identification_number'].PHP_EOL.'ValFac: '.$data['legalMonetaryTotals']['tax_exclusive_amount'].PHP_EOL.'ValIva: '.'0.00'.PHP_EOL.'ValOtroIm: '.$data['legalMonetaryTotals']['allowance_total_amount'].PHP_EOL.'ValTotal: '.$data['legalMonetaryTotals']['payable_amount'].PHP_EOL.'CUFE: -----CUFECUDE-----'.PHP_EOL.$urlquery.'/document/searchqr?documentkey=-----CUFECUDE-----';
//            else
//                if($data['typeDocument']['code'] === '91' or $data['typeDocument']['code'] === '92' or $data['typeDocument']['code'] === '93' or $data['typeDocument']['code'] === '94'){
//                    if(isset($data['request']['tax_totals'][0]['tax_amount']))
//                        if(in_array($data['typeDocument']['code'], ['93', '92']))
//                            $QRCode = 'NumFac: '.$data['resolution']['next_consecutive'].PHP_EOL.'FecFac: '.$data['date'].PHP_EOL.'NitFac: '.$data['user']['company']['identification_number'].PHP_EOL.'DocAdq: '.$data['customer']['company']['identification_number'].PHP_EOL.'ValFac: '.$data['requestedMonetaryTotals']['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$data['request']['tax_totals'][0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$data['requestedMonetaryTotals']['allowance_total_amount'].PHP_EOL.'ValTotal: '.$data['requestedMonetaryTotals']['payable_amount'].PHP_EOL.'CUFE: -----CUFECUDE-----'.PHP_EOL.$urlquery.'/document/searchqr?documentkey=-----CUFECUDE-----';
//                        else
//                            $QRCode = 'NumFac: '.$data['resolution']['next_consecutive'].PHP_EOL.'FecFac: '.$data['date'].PHP_EOL.'NitFac: '.$data['user']['company']['identification_number'].PHP_EOL.'DocAdq: '.$data['customer']['company']['identification_number'].PHP_EOL.'ValFac: '.$data['legalMonetaryTotals']['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$data['request']['tax_totals'][0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$data['legalMonetaryTotals']['allowance_total_amount'].PHP_EOL.'ValTotal: '.$data['legalMonetaryTotals']['payable_amount'].PHP_EOL.'CUFE: -----CUFECUDE-----'.PHP_EOL.$urlquery.'/document/searchqr?documentkey=-----CUFECUDE-----';
//                    else
//                        if(in_array($data['typeDocument']['code'], ['93', '92']))
//                            $QRCode = 'NumFac: '.$data['resolution']['next_consecutive'].PHP_EOL.'FecFac: '.$data['date'].PHP_EOL.'NitFac: '.$data['user']['company']['identification_number'].PHP_EOL.'DocAdq: '.$data['customer']['company']['identification_number'].PHP_EOL.'ValFac: '.$data['requestedMonetaryTotals']['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: '.$data['requestedMonetaryTotals']['allowance_total_amount'].PHP_EOL.'ValTotal: '.$data['requestedMonetaryTotals']['payable_amount'].PHP_EOL.'CUFE: -----CUFECUDE-----'.PHP_EOL.$urlquery.'/document/searchqr?documentkey=-----CUFECUDE-----';
//                        else
//                            $QRCode = 'NumFac: '.$data['resolution']['next_consecutive'].PHP_EOL.'FecFac: '.$data['date'].PHP_EOL.'NitFac: '.$data['user']['company']['identification_number'].PHP_EOL.'DocAdq: '.$data['customer']['company']['identification_number'].PHP_EOL.'ValFac: '.$data['legalMonetaryTotals']['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: '.$data['legalMonetaryTotals']['allowance_total_amount'].PHP_EOL.'ValTotal: '.$data['legalMonetaryTotals']['payable_amount'].PHP_EOL.'CUFE: -----CUFECUDE-----'.PHP_EOL.$urlquery.'/document/searchqr?documentkey=-----CUFECUDE-----';
//                }
//                else
//                    if($data['typeDocument']['code'] === '05' or $data['typeDocument']['code'] === '95')
//                        $QRCode = $urlquery.'/document/searchqr?documentkey=-----CUFECUDE-----';
//                    else
//                        $QRCode = 'NumFac: '.$data['resolution']['next_consecutive'].PHP_EOL.'FecFac: '.$data['date'].PHP_EOL.'NitFac: '.$data['user']['company']['identification_number'].PHP_EOL.'DocAdq: '.$data['customer']['company']['identification_number'].PHP_EOL.'ValFac: '.$data['requestedMonetaryTotals']['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$data['request']['tax_totals'][0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$data['requestedMonetaryTotals']['allowance_total_amount'].PHP_EOL.'ValTotal: '.$data['requestedMonetaryTotals']['payable_amount'].PHP_EOL.'CUFE: -----CUFECUDE-----'.PHP_EOL.$urlquery.'/document/searchqr?documentkey=-----CUFECUDE-----';
            $data['QRCode'] = $QRCode;
        }
        else{
            if($data['typeDocument']['code'] === '88')
                $urlquery = 'https://catalogo-vpfe.dian.gov.co';
            else
                if($data['company']['payroll_type_environment_id'] == 2)
                    $urlquery = 'https://catalogo-vpfe-hab.dian.gov.co';
                else
                    $urlquery = 'https://catalogo-vpfe.dian.gov.co';
            $QRCode = $urlquery.'/document/searchqr?documentkey=-----CUFECUDE-----';
            $data['QRCode'] = $QRCode;
        }
        try {
            $DOMDocumentXML = new DOMDocument();
            $DOMDocumentXML->preserveWhiteSpace = false;
            $DOMDocumentXML->formatOutput = true;
            if(isset($data['request']['is_eqdoc']) && ($data['request']['is_eqdoc'] == true)){
                if($data['request']['is_eqdoc'] == true && $data['typeDocument']['code'] == 94)
                    $DOMDocumentXML->loadXML(view("xml.91", $data)->render());
                if($data['request']['is_eqdoc'] == true && $data['typeDocument']['code'] == 93)
                    $DOMDocumentXML->loadXML(view("xml.92", $data)->render());
            }
            else{
                $DOMDocumentXML->loadXML(view("xml.{$data['typeDocument']['code']}", $data)->render());
            }
            if(isset($data['signedxml']) and ($data['typeDocument']['code'] === '89')){
                $rootNode = $DOMDocumentXML->documentElement;
                $nodeCDATAInvoice = $rootNode->getElementsByTagName("ExternalReference")->item(0);
                $elementCDATA = $DOMDocumentXML->createElement('cbc:Description');
                $CDATA = $DOMDocumentXML->createCDATASection($data['signedxml']);
                $elementCDATA->appendChild($CDATA);
                $nodeCDATAInvoice->appendChild($elementCDATA);
            }

            if(isset($data['appresponsexml']) and ($data['typeDocument']['code'] === '89')){
                $rootNode = $DOMDocumentXML->documentElement;
                $nodeCDATAAppResponse = $rootNode->getElementsByTagName("ExternalReference")->item(1);
                $elementCDATA = $DOMDocumentXML->createElement('cbc:Description');
                $CDATA = $DOMDocumentXML->createCDATASection($data['appresponsexml']);
                $elementCDATA->appendChild($CDATA);
                $nodeCDATAAppResponse->appendChild($elementCDATA);
            }

            return $DOMDocumentXML;
        } catch (InvalidArgumentException $e) {
            throw new Exception("The API does not support the type of document '{$data['typeDocument']['name']}' Error: {$e->getMessage()}");
        } catch (Exception $e) {
            throw new Exception("Error: {$e->getMessage()}");
        }
    }

    /**
     * Resolver la plantilla PDF segun el tipo de documento.
     *
     * Cada tipo tiene su propia columna en companies; si esta en NULL (o la
     * vista no existe para esa carpeta) se usa graphic_representation_template,
     * que queda como la plantilla de facturas y notas credito/debito, y valor
     * por defecto para el resto.
     *
     * @param \App\Company $company
     * @param string $tipodoc
     *
     * @return int|null
     */
    protected function resolveTemplatePdf($company, $tipodoc)
    {
        $default = $company->graphic_representation_template;

        // [carpeta de vistas, plantilla configurada, usa header/footer numerados].
        // NC/ND y la nota de ajuste usan header/footer genericos (sin numero);
        // solo el cuerpo (templateN) es numerado en esos flujos.
        $map = [
            'INVOICE' => ['invoice', $default, true],
            // Documentos equivalentes: todos los subtipos comparten la columna
            // eqdocs_template, cada uno en su carpeta (con fallback si esa id no
            // existe alli). POS->pos, transporte->ttr, servicios/cine->srv.
            'POS' => ['pos', $company->eqdocs_template, true],
            'TTR' => ['ttr', $company->eqdocs_template, true],
            'SRV' => ['srv', $company->eqdocs_template, true],
            'CIN' => ['srv', $company->eqdocs_template, true],
            'NC' => ['credit-note', $default, false],
            'ND' => ['debit-note', $default, false],
            'SUPPORTDOCUMENT' => ['support', $company->support_document_template, true],
            'SUPPORTDOCUMENTNOTE' => ['support-credit-note', $company->support_document_template, false],
        ];

        if (!isset($map[$tipodoc])) {
            return $default;
        }

        list($folder, $template, $numberedHeader) = $map[$tipodoc];

        if (($template ?? $default) === null) {
            return $default;
        }

        // Primer candidato cuyas vistas existan completas para esta carpeta;
        // la 2 existe en todas las carpetas y cierra la cadena.
        foreach (array_unique([$template ?? $default, $default, 2]) as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $ok = View::exists("pdfs.{$folder}.template{$candidate}")
                && (!$numberedHeader || (View::exists("pdfs.{$folder}.header{$candidate}") && View::exists("pdfs.{$folder}.footer{$candidate}")));
            if ($ok) {
                return $candidate;
            }
        }

        return $default;
    }

    /**
     * Create pdf.
     *
     * @param array $data
     *
     * @return DOMDocument
     */
    protected function createPDF($user, $company, $customer, $typeDocument, $resolution, $date, $time, $paymentForm, $request, $cufecude, $tipodoc = "INVOICE", $withHoldingTaxTotal = NULL, $notes = NULL, $healthfields)
    {
        StorageService::setCompany($company);
        set_time_limit(0);
        ini_set("pcre.backtrack_limit", "5000000");
        define("DOMPDF_ENABLE_REMOTE", true);

        $template_json = false;
        $template_pdf = $this->resolveTemplatePdf($company, $tipodoc);

        // Plantillas con configuracion mPdf propia (margenes/styles{N}.css):
        // en generacion normal deben ir por la rama numerada de initMPdf.
        if (empty($request->invoice_template) && in_array($template_pdf, [3, 10, 11, 12, 13])) {
            $template_json = true;
        }
        if (!empty($request->invoice_template)) {
            if (password_verify($company->identification_number, $request->template_token)) {
                $template_pdf = $request->invoice_template;
                $template_json = true;
            }
        }

        if (isset($request->is_tirilla58) && $request->is_tirilla58) {
            $temp_template_pdf = 9;
        } elseif (isset($request->is_tirilla2) && $request->is_tirilla2) {
            $temp_template_pdf = 7;
        } else {
            $temp_template_pdf = $template_pdf;
        }

        $QRStr = '';
//        try {
            if(isset($request->establishment_logo)){
                $filenameLogo   = StorageService::localPath("public/{$company->identification_number}/alternate_{$company->identification_number}{$company->dv}.jpg");
                $this->storeLogo($request->establishment_logo);
            }
            else
                $filenameLogo   = StorageService::localPath("public/{$company->identification_number}/{$company->identification_number}{$company->dv}.jpg");

            $firma_facturacion = null;
            if(isset($request->firma_facturacion)){
                $firma_facturacion = "data:image/jpg;base64, ".$request->firma_facturacion;
            }

            $logo_empresa_emisora = null;
            if(isset($request->logo_empresa_emisora)){
                $logo_empresa_emisora = "data:image/jpg;base64, ".$request->logo_empresa_emisora;
            }

            $imgLogo = $this->buildImageDataUriFromFile($filenameLogo);

            if($tipodoc == "ND")
                $totalbase = $request->requested_monetary_totals['line_extension_amount'];
            else
                $totalbase = $request->legal_monetary_totals['line_extension_amount'];

            if($tipodoc == 'SRV' or $tipodoc == 'CIN')
                if($company->eqdocs_type_environment_id == 1)
                    $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                else
                    $QRStr = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;

            if ($tipodoc == 'TTR') {
                $pdf = $this->initMPdf('ttr', $temp_template_pdf);
                $pdf->SetHTMLHeader(View::make("pdfs.".strtolower($tipodoc).".header".$template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields", "firma_facturacion", "logo_empresa_emisora")));
                $pdf->SetHTMLFooter(View::make("pdfs.".strtolower($tipodoc).".footer".$template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields", "firma_facturacion", "logo_empresa_emisora")));
                $pdf->WriteHTML(View::make("pdfs.".strtolower($tipodoc).".template".$temp_template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields", "firma_facturacion", "logo_empresa_emisora")), HTMLParserMode::HTML_BODY);
                $filename = StorageService::tempPath("public/{$company->identification_number}/TTRS-{$resolution->next_consecutive}.pdf");
                // dd($filename);

                if ($company->eqdocs_type_environment_id == 1) {
                    $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey=' . $cufecude;
                } else {
                    $QRStr = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey=' . $cufecude;
                }
            }
            if($tipodoc == 'SRV' || $tipodoc == 'CIN') {
                $qrBase64 = base64_encode(QrCode::format('png')
                    ->errorCorrection('Q')
                    ->size(220)
                    ->margin(0)
                    ->generate($QRStr)
                );
                $imageQr = "data:image/png;base64, ".$qrBase64;

                // SRV y CIN comparten esta rama y la carpeta 'srv' (no existe
                // carpeta 'cin'); se usa el literal 'srv' en vez de strtolower.
                $pdf = $this->initMPdf('srv', $temp_template_pdf);
                $pdf->SetHTMLHeader(View::make("pdfs.srv.header".$template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields", "firma_facturacion", "logo_empresa_emisora")));
                $pdf->SetHTMLFooter(View::make("pdfs.srv.footer".$template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields", "firma_facturacion", "logo_empresa_emisora")));
                $pdf->WriteHTML(View::make("pdfs.srv.template".$temp_template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields", "firma_facturacion", "logo_empresa_emisora")), HTMLParserMode::HTML_BODY);
                $filename = StorageService::tempPath("public/{$company->identification_number}/SRVS-{$resolution->next_consecutive}.pdf");
                $pdf->Output($filename);
                StorageService::putLocalFile("public/{$company->identification_number}/SRVS-{$resolution->next_consecutive}.pdf", $filename, StorageService::isS3());
                if (file_exists($filename) || StorageService::exists("public/{$company->identification_number}/SRVS-{$resolution->next_consecutive}.pdf")) {
                    \Log::info("PDF generado correctamente: " . $filename);
                } else {
                    \Log::error("No se pudo generar el PDF: " . $filename);
                }
                return $QRStr;
            }

            if($tipodoc == "INVOICE" || $tipodoc == "POS"){
                if($company->type_environment_id == 2){
                    if(isset($request->tax_totals[0]['tax_amount'])){
                        $qrBase64 = base64_encode(QrCode::format('png')
                                                ->errorCorrection('Q')
                                                ->size(220)
                                                ->margin(0)
//                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                ->generate('https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                        $QRStr = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                    }
                    else{
                        $qrBase64 = base64_encode(QrCode::format('png')
                                                ->errorCorrection('Q')
                                                ->size(220)
                                                ->margin(0)
//                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                ->generate('https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                        $QRStr = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                    }
                }
                else{
                    if(isset($request->tax_totals[0]['tax_amount'])){
                        $qrBase64 = base64_encode(QrCode::format('png')
                                                ->errorCorrection('Q')
                                                ->size(220)
                                                ->margin(0)
//                                            ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                    $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                ->generate('https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                        $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                    }
                    else{
                        $qrBase64 = base64_encode(QrCode::format('png')
                                                ->errorCorrection('Q')
                                                ->size(220)
                                                ->margin(0)
//                                            ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                    $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                ->generate('https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                        $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                    }
                }
                $imageQr    =  "data:image/png;base64, ".$qrBase64;

                if($template_json){
                    if($tipodoc == 'POS')
                        $pdf = $this->initMPdf('pos', $temp_template_pdf);
                    else
                        $pdf = $this->initMPdf('invoice', $temp_template_pdf);
                    $this->setPreviewWatermark($pdf, $request, $cufecude);
                    $pdf->SetHTMLHeader(View::make("pdfs.".strtolower($tipodoc).".header".$template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields", "firma_facturacion", "logo_empresa_emisora")));
                    $pdf->SetHTMLFooter(View::make("pdfs.".strtolower($tipodoc).".footer".$template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields", "firma_facturacion", "logo_empresa_emisora")));
                    $pdf->WriteHTML(View::make("pdfs.".strtolower($tipodoc).".template".$temp_template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields", "firma_facturacion", "logo_empresa_emisora")), HTMLParserMode::HTML_BODY);
                }
                else{
                    if($tipodoc == 'POS')
                        $pdf = $this->initMPdf('pos', $temp_template_pdf);
                    else
                        $pdf = $this->initMPdf();
                    $this->setPreviewWatermark($pdf, $request, $cufecude);
                    $pdf->SetHTMLHeader(View::make("pdfs.".strtolower($tipodoc).".header".$template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields", "firma_facturacion", "logo_empresa_emisora")));
                    $pdf->SetHTMLFooter(View::make("pdfs.".strtolower($tipodoc).".footer".$template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields", "firma_facturacion", "logo_empresa_emisora")));
                    $pdf->WriteHTML(View::make("pdfs.".strtolower($tipodoc).".template".$temp_template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields", "firma_facturacion", "logo_empresa_emisora")), HTMLParserMode::HTML_BODY);
//                    $pdf->SetHTMLHeader(View::make("pdfs.invoice.header", compact("resolution", "date", "time", "user", "request", "company", "imgLogo")));
//                    $pdf->SetHTMLFooter(View::make("pdfs.invoice.footer", compact("resolution", "request", "cufecude", "date", "time")));
//                    $pdf->WriteHTML(View::make("pdfs.invoice.template".$template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields")), HTMLParserMode::HTML_BODY);
                }

                if($tipodoc == 'POS')
                    $filename = StorageService::tempPath("public/{$company->identification_number}/POSS-{$resolution->next_consecutive}.pdf");
                else
                    $filename = StorageService::tempPath("public/{$company->identification_number}/FES-{$resolution->next_consecutive}.pdf");
            }
            else
                if($tipodoc == "NC"){
                    if ($company->type_environment_id == 2){
                        if(isset($request->tax_totals[0]['tax_amount']))
                            $qrBase64 = base64_encode(QrCode::format('png')
                                                    ->errorCorrection('Q')
                                                    ->size(220)
                                                    ->margin(0)
//                                              ->generate('NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                              $QRStr = 'NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                ->generate('NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                ->generate('https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                        else
                                $qrBase64 = base64_encode(QrCode::format('png')
                                                    ->errorCorrection('Q')
                                                    ->size(220)
                                                    ->margin(0)
//                                              ->generate('NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                              $QRStr = 'NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                ->generate('NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                ->generate('https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                $QRStr = 'NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                $QRStr = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                    }
                    else{
                        if(isset($request->tax_totals[0]['tax_amount'])){
                            $qrBase64 = base64_encode(QrCode::format('png')
                                                ->errorCorrection('Q')
                                                ->size(220)
                                                ->margin(0)
//                                                ->generate('NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                        $QRStr = 'NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                ->generate('NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                ->generate('https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                            $QRStr = 'NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                            $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                        }
                        else{
                            $qrBase64 = base64_encode(QrCode::format('png')
                                                ->errorCorrection('Q')
                                                ->size(220)
                                                ->margin(0)
//                                                ->generate('NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                        $QRStr = 'NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                ->generate('NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                ->generate('https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                            $QRStr = 'NumCr: '.$request->number.PHP_EOL.'FecCr: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                            $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                        }
                    }
                    $imageQr    =  "data:image/png;base64, ".$qrBase64;

                    $pdf = $this->initMPdf('credit-note');
                    $pdf->SetHTMLHeader(View::make("pdfs.credit-note.header", compact("resolution", "date", "time", "user", "request", "company", "imgLogo")));
                    $pdf->SetHTMLFooter(View::make("pdfs.credit-note.footer", compact("resolution", "request", "cufecude", "date", "time")));
                    $pdf->WriteHTML(View::make("pdfs.credit-note.template".$template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields")), HTMLParserMode::HTML_BODY);
                    $filename = StorageService::tempPath("public/{$company->identification_number}/NCS-{$resolution->next_consecutive}.pdf");
                    $pdfPrefix = 'NCS';
                    if (!empty($request->is_eqdoc) && $request->is_eqdoc) {
                        $typePrefix = isset($typeDocument->prefix) ? $typeDocument->prefix : 'NCQ';
                        $pdfPrefix = strtoupper($typePrefix) . 'S';
                    }
                    $filename = StorageService::tempPath("public/{$company->identification_number}/{$pdfPrefix}-{$resolution->next_consecutive}.pdf");
                }
                else{
                    if($tipodoc == "ND"){
                        if($company->type_environment_id == 2){
                            if(isset($request->tax_totals[0]['tax_amount'])){
                                $qrBase64 = base64_encode(QrCode::format('png')
                                                    ->errorCorrection('Q')
                                                    ->size(220)
                                                    ->margin(0)
//                                                    ->generate('NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->requested_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                            $QRStr = 'NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->requested_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                        ->generate('NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                        ->generate('https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                $QRStr = 'NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                $QRStr = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                            }
                            else{
                                $qrBase64 = base64_encode(QrCode::format('png')
                                                    ->errorCorrection('Q')
                                                    ->size(220)
                                                    ->margin(0)
//                                                    ->generate('NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->requested_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                            $QRStr = 'NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->requested_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                        ->generate('NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                        ->generate('https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                $QRStr = 'NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                $QRStr = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                            }
                        }
                        else{
                            if(isset($request->tax_totals[0]['tax_amount'])){
                                $qrBase64 = base64_encode(QrCode::format('png')
                                                    ->errorCorrection('Q')
                                                    ->size(220)
                                                    ->margin(0)
//                                                    ->generate('NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->requested_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                            $QRStr = 'NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->requested_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                    ->generate('NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                    ->generate('https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                $QRStr = 'NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                            }
                            else{
                                $qrBase64 = base64_encode(QrCode::format('png')
                                                    ->errorCorrection('Q')
                                                    ->size(220)
                                                    ->margin(0)
//                                                    ->generate('NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->requested_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                            $QRStr = 'NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->requested_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                    ->generate('NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                    ->generate('https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                $QRStr = 'NumDb: '.$request->number.PHP_EOL.'FecDb: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->requested_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->requested_monetary_totals['payable_amount'].PHP_EOL.'CUDE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                            }
                        }
                        $imageQr    =  "data:image/png;base64, ".$qrBase64;

                        $pdf = $this->initMPdf('debit-note');
                        $pdf->SetHTMLHeader(View::make("pdfs.debit-note.header", compact("resolution", "date", "time", "user", "request", "company", "imgLogo")));
                        $pdf->SetHTMLFooter(View::make("pdfs.debit-note.footer", compact("resolution", "request", "cufecude", "date", "time")));
                        $pdf->WriteHTML(View::make("pdfs.debit-note.template".$template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields")), HTMLParserMode::HTML_BODY);

                        $pdfPrefix = 'NDS';
                        if (!empty($request->is_eqdoc) && $request->is_eqdoc) {
                            $typePrefix = isset($typeDocument->prefix) ? $typeDocument->prefix : 'NDQ';
                            $pdfPrefix = strtoupper($typePrefix) . 'S';
                        }
                        $filename = StorageService::tempPath("public/{$company->identification_number}/{$pdfPrefix}-{$resolution->next_consecutive}.pdf");
                    }
                    else
                        if($tipodoc == "SUPPORTDOCUMENT"){
                            if($company->support_document_type_environment_id == 2){
                                if(isset($request->tax_totals[0]['tax_amount'])){
                                    $qrBase64 = base64_encode(QrCode::format('png')
                                                            ->errorCorrection('Q')
                                                            ->size(220)
                                                            ->margin(0)
//                                                            ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                    $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                            ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                            ->generate('https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                    $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                    $QRStr = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                }
                                else{
                                    $qrBase64 = base64_encode(QrCode::format('png')
                                                            ->errorCorrection('Q')
                                                            ->size(220)
                                                            ->margin(0)
//                                                            ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                    $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                            ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                            ->generate('https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                    $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                    $QRStr = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                }
                            }
                            else{
                                if(isset($request->tax_totals[0]['tax_amount'])){
                                    $qrBase64 = base64_encode(QrCode::format('png')
                                                            ->errorCorrection('Q')
                                                            ->size(220)
                                                            ->margin(0)
//                                                            ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                    $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                            ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                            ->generate('https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                    $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                    $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                }
                                else{
                                    $qrBase64 = base64_encode(QrCode::format('png')
                                                            ->errorCorrection('Q')
                                                            ->size(220)
                                                            ->margin(0)
//                                                            ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                    $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                            ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                            ->generate('https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                    $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                    $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                }
                            }
                            $imageQr    =  "data:image/png;base64, ".$qrBase64;

                            $pdf = $this->initMPdf();
                            $pdf->SetHTMLHeader(View::make("pdfs.support.header".$template_pdf, compact("resolution", "date", "time", "user", "request", "company", "imgLogo", "logo_empresa_emisora")));
                            $pdf->SetHTMLFooter(View::make("pdfs.support.footer".$template_pdf, compact("resolution", "request", "cufecude", "date", "time", "logo_empresa_emisora")));
                            $pdf->WriteHTML(View::make("pdfs.support.template".$template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields", "logo_empresa_emisora")), HTMLParserMode::HTML_BODY);

                            $filename = StorageService::tempPath("public/{$company->identification_number}/DSS-{$resolution->next_consecutive}.pdf");
                        }
                        else
                            if($tipodoc == "SUPPORTDOCUMENTNOTE"){
                                if($company->support_document_type_environment_id == 2){
                                    if(isset($request->tax_totals[0]['tax_amount'])){
                                        $qrBase64 = base64_encode(QrCode::format('png')
                                                                ->errorCorrection('Q')
                                                                ->size(220)
                                                                ->margin(0)
//                                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                                ->generate('https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                        $QRStr = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                    }
                                    else{
                                        $qrBase64 = base64_encode(QrCode::format('png')
                                                                ->errorCorrection('Q')
                                                                ->size(220)
                                                                ->margin(0)
//                                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                                ->generate('https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                        $QRStr = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                    }
                                }
                                else{
                                    if(isset($request->tax_totals[0]['tax_amount'])){
                                        $qrBase64 = base64_encode(QrCode::format('png')
                                                                ->errorCorrection('Q')
                                                                ->size(220)
                                                                ->margin(0)
//                                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                                ->generate('https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                        $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                    }
                                    else{
                                        $qrBase64 = base64_encode(QrCode::format('png')
                                                                ->errorCorrection('Q')
                                                                ->size(220)
                                                                ->margin(0)
//                                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: '.$request->tax_totals[0]['tax_amount'].PHP_EOL.'ValOtroIm: '.$request->legal_monetary_totals['allowance_total_amount'].PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
//                                                                ->generate('NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                                                                ->generate('https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
//                                        $QRStr = 'NumFac: '.$request->number.PHP_EOL.'FecFac: '.$date.PHP_EOL.'NitFac: '.$company->identification_number.PHP_EOL.'DocAdq: '.$customer->company->identification_number.PHP_EOL.'ValFac: '.$request->legal_monetary_totals['tax_exclusive_amount'].PHP_EOL.'ValIva: 0.00'.PHP_EOL.'ValOtroIm: 0.00'.PHP_EOL.'ValTotal: '.$request->legal_monetary_totals['payable_amount'].PHP_EOL.'CUFE: '.$cufecude.PHP_EOL.'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                        $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                                    }
                                }
                                $imageQr    =  "data:image/png;base64, ".$qrBase64;

                                $pdf = $this->initMPdf();
                                $pdf->SetHTMLHeader(View::make("pdfs.support-credit-note.header", compact("resolution", "date", "time", "user", "request", "company", "imgLogo")));
                                $pdf->SetHTMLFooter(View::make("pdfs.support-credit-note.footer", compact("resolution", "request", "cufecude", "date", "time")));
                                $pdf->WriteHTML(View::make("pdfs.support-credit-note.template".$template_pdf, compact("user", "company", "customer", "resolution", "date", "time", "paymentForm", "request", "cufecude", "imageQr", "imgLogo", "withHoldingTaxTotal", "notes", "healthfields")), HTMLParserMode::HTML_BODY);

                                $filename = StorageService::tempPath("public/{$company->identification_number}/NDSNS-{$resolution->next_consecutive}.pdf");
                            }
            }
            $pdf->Output($filename);
            // Upload generated PDF to configured storage (S3 or local)
            $relativePath = str_replace(StorageService::tempPath(''), '', $filename);
            if (empty($relativePath) || $relativePath === $filename) {
                $relativePath = "public/{$company->identification_number}/" . basename($filename);
            }
            StorageService::putLocalFile($relativePath, $filename, StorageService::isS3());
            return $QRStr;
    }

    /**
     * Create payroll pdf.
     *
     * @param array $data
     *
     * @return DOMDocument
     */
    protected function createPDFPayroll($user, $company, $predecessor, $period, $worker, $resolution, $payment, $payment_dates, $typeDocument, $notes = NULL, $accrued, $deductions, $request, $cufecude, $tipodoc = "PAYROLL")
    {
        StorageService::setCompany($company);
        set_time_limit(0);
        ini_set("pcre.backtrack_limit", "5000000");
        $QRStr = '';
//        try {
            define("DOMPDF_ENABLE_REMOTE", true);
            if(isset($request->establishment_logo)){
                $filenameLogo   = StorageService::localPath("public/{$company->identification_number}/alternate_{$company->identification_number}{$company->dv}.jpg");
                $this->storeLogo($request->establishment_logo);
            }
            else
                $filenameLogo   = StorageService::localPath("public/{$company->identification_number}/{$company->identification_number}{$company->dv}.jpg");

            $imgLogo = $this->buildImageDataUriFromFile($filenameLogo);
            if($tipodoc = "PAYROLL"){
                if ($company->payroll_type_environment_id == 2){
                    $qrBase64 = base64_encode(QrCode::format('png')
                                            ->errorCorrection('Q')
                                            ->size(220)
                                            ->margin(0)
                                            ->generate('https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                    $QRStr = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                }
                else{
                    $qrBase64 = base64_encode(QrCode::format('png')
                                            ->errorCorrection('Q')
                                            ->size(220)
                                            ->margin(0)
                                            ->generate('https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                    $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
                }
            }

            $imageQr    =  "data:image/png;base64, ".$qrBase64;
            $template_pdf = $company->payroll_template ?? $company->graphic_representation_template ?? 1;
            // Override de plantilla solo para vista previa (dueño de la empresa con
            // template_token valido); permite previsualizar la 2 u la 8 sin cambiar
            // la configuracion guardada de la empresa.
            if (!empty($request->payroll_template) && !empty($request->template_token)
                && password_verify($company->identification_number, $request->template_token)) {
                $template_pdf = (int) $request->payroll_template;
            }
            // Si es nómina y el template no es 2 ni 8, forzar el template 2
            if (!in_array($template_pdf, [2, 8])) {
                $template_pdf = 2;
            }
            $temp_template_pdf = $template_pdf;
            $temp_template_pdf = $template_pdf;

            $pdf = $this->initMPdf('payroll', $temp_template_pdf);
            $pdf->SetHTMLHeader(View::make("pdfs.payroll.header". $template_pdf, compact("resolution", "period", "user", "request", "company", "imgLogo")));
            $pdf->SetHTMLFooter(View::make("pdfs.payroll.footer". $template_pdf, compact("resolution", "request", "cufecude", "period")));
            $pdf->WriteHTML(View::make("pdfs.payroll.template". $temp_template_pdf, compact("user", "company", "predecessor", "period", "worker", "resolution", "payment", "typeDocument", "notes", "accrued", "deductions", "request", "imageQr")), HTMLParserMode::HTML_BODY);

            if($request->type_document_id == 9)
                $filename = StorageService::tempPath("public/{$company->identification_number}/NIS-{$resolution->next_consecutive}.pdf");
            else
                $filename = StorageService::tempPath("public/{$company->identification_number}/NAS-{$resolution->next_consecutive}.pdf");
            $pdf->Output($filename);
            // Upload generated PDF to configured storage
            $pdfBasename = basename($filename);
            StorageService::putLocalFile("public/{$company->identification_number}/{$pdfBasename}", $filename, StorageService::isS3());
//            return compact("resolution", "period", "user", "request", "company", "imgLogo");
            return $QRStr;
    }

    /**
     * Create event pdf.
     *
     * @param array $data
     *
     * @return DOMDocument
     */
    protected function createPDFEvent($user, $company, $typeDocument, $event, $sender, $documentReference, $typeDocumentReference, $issuerparty, $typerejection, $notes, $request, $cufecude)
    {
        StorageService::setCompany($company);
        set_time_limit(0);
        ini_set("pcre.backtrack_limit", "5000000");
        $QRStr = '';
//        try {
            define("DOMPDF_ENABLE_REMOTE", true);
            if(isset($request->establishment_logo)){
                $filenameLogo   = StorageService::localPath("public/{$company->identification_number}/alternate_{$company->identification_number}{$company->dv}.jpg");
                $this->storeLogo($request->establishment_logo);
            }
            else
                $filenameLogo   = StorageService::localPath("public/{$company->identification_number}/{$company->identification_number}{$company->dv}.jpg");

            $imgLogo = $this->buildImageDataUriFromFile($filenameLogo);
            if ($company->payroll_type_environment_id == 2){
                $qrBase64 = base64_encode(QrCode::format('png')
                                        ->errorCorrection('Q')
                                        ->size(220)
                                        ->margin(0)
                                        ->generate('https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                $QRStr = 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufecude;
            }
            else{
                $qrBase64 = base64_encode(QrCode::format('png')
                                        ->errorCorrection('Q')
                                        ->size(220)
                                        ->margin(0)
                                        ->generate('https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude));
                $QRStr = 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$cufecude;
            }

            $imageQr    =  "data:image/png;base64, ".$qrBase64;
            $pdf = $this->initMPdf('event');
            $pdf->SetHTMLHeader(View::make("pdfs.event.header", compact("user", "company", "typeDocument", "event", "sender", "documentReference", "typeDocumentReference", "issuerparty", "typerejection", "notes", "request", "cufecude", "imageQr", "imgLogo")));
            $pdf->SetHTMLFooter(View::make("pdfs.event.footer", compact("user", "company", "typeDocument", "event", "sender", "documentReference", "typeDocumentReference", "issuerparty", "typerejection", "notes", "request", "cufecude", "imageQr", "imgLogo")));
            $pdf->WriteHTML(View::make("pdfs.event.template", compact("user", "company", "typeDocument", "event", "sender", "documentReference", "typeDocumentReference", "issuerparty", "typerejection", "notes", "request", "cufecude", "imageQr", "imgLogo")), HTMLParserMode::HTML_BODY);

            $evtPdfName = preg_replace("/[\r\n|\n|\r]+/", "", "EVS-{$sender->company->identification_number}{$documentReference->number}{$event->code}.pdf");
            $filename = preg_replace("/[\r\n|\n|\r]+/", "", StorageService::tempPath("public/{$company->identification_number}/{$evtPdfName}"));
            $pdf->Output($filename);
            // Upload generated PDF to configured storage
            StorageService::putLocalFile("public/{$company->identification_number}/{$evtPdfName}", $filename, StorageService::isS3());
//            return compact("resolution", "period", "user", "request", "company", "imgLogo");
            return $QRStr;
    }

    protected function setPreviewWatermark(Mpdf $pdf, $request, $cufecude = '')
    {
        if ($request->is_preview && empty($cufecude)) {
            $pdf->SetWatermarkText(new \Mpdf\WatermarkText('SIN VALOR', 96, 45, '#FF0000', 0.15));
            $pdf->showWatermarkText = true;
            $pdf->watermark_font = 'DejaVuSansCondensed';
        }
    }

    protected function initMPdf(string $type = 'invoice', string $template = null): Mpdf
    {
        $defaultConfig = (new ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];

        $defaultFontConfig = (new FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];
        $margin_left = null;
        $margin_right = null;
        $margin_top = null;
        $margin_bottom = null;
        $margin_header = 5;
        $margin_footer = 2;
        $auto_bottom_margin = false;

        $filename = base_path('resources/views/pdfs/' . $type . '/config'.$template.'.json');
            if (file_exists($filename)) {
            $jsonD =  file_get_contents('config'.$template.'.json');
            $margin = json_decode($jsonD,true);
            if(isset($margin)){
                $arr_margin = explode(",",$request->margin);
                $margin_top = $margin['top'];
                $margin_right = $margin['right'];
                $margin_bottom = $margin['bottom'];
                $margin_left = $margin['left'];
            }
        }

         if($template == 1){

            $format_print = [148, 210];
            $margin_top = '25';
            $margin_left = '5';
            $margin_right = '5';
            $margin_bottom = '10';
            $orientation = 'L';

         }elseif($template == 2 || $template == 8)  {

            $format_print = 'A4';
            $margin_top = '39';
            $margin_left = '10';
            $margin_right = '10';
            $margin_bottom = '15';

         } elseif($template == 3 || $template == 7)  {


            $margin_top = '2';
            // Definir el ancho y el alto en milímetros (por ejemplo, para un largo de 150mm)
            $ancho = 80;  // 80mm de ancho para la impresora de tirilla
            $alto = 300;   // El alto puede ser más largo dependiendo del contenido a imprimir
            $margin_left = '2';
            $margin_right = '2';
            $margin_bottom = '5';

            $format_print = [$ancho, $alto];

         } elseif($template == 9)  {

            $margin_top = '2';
            // Tirilla de 58mm de ancho (54mm útiles tras los márgenes)
            $ancho = 58;  // 58mm de ancho para la impresora de tirilla
            $alto = 300;   // El alto puede ser más largo dependiendo del contenido a imprimir
            $margin_left = '2';
            $margin_right = '2';
            $margin_bottom = '5';

            $format_print = [$ancho, $alto];

         } elseif($template == 10)  {

            // Plantilla 10 (diseño minimalista con bloques De/A): margenes
            // simetricos y espacio inferior para el pie con CUFE y paginacion.
            $format_print = 'A4';
            $margin_top = '32';
            $margin_left = '10';
            $margin_right = '10';
            $margin_bottom = '22';

         } elseif($template == 11)  {

            // Plantilla 11 (encabezado con CUFE, autorizacion, fechas y QR):
            // el header es alto, el cuerpo arranca mas abajo.
            $format_print = 'A4';
            $margin_top = '54';
            $margin_left = '8';
            $margin_right = '8';
            $margin_bottom = '14';

         } elseif($template == 12)  {

            // Plantilla 12 (diseño azul con logo/titulo + metadatos/QR en el
            // encabezado): header alto, cuerpo arranca bajo la caja de metadatos.
            $format_print = 'A4';
            $margin_top = '52';
            $margin_left = '10';
            $margin_right = '10';
            $margin_bottom = '14';

         } elseif($template == 13)  {

            // Plantilla 13: Media Carta Vertical. Mismo diseño que la 1 pero
            // en retrato. El cuerpo (header + tabla) no debe pasar la mitad de
            // la hoja: con margin_bottom = 105 (la mitad de 210 mm) el salto de
            // pagina se dispara justo en la linea media, asi que si hay mas
            // items mPDF continua en otra pagina sin bajar de la mitad. El pie
            // se mantiene anclado al fondo de la hoja (margin_footer = 2).
            $format_print = [148, 210];
            $margin_top = '33';
            $margin_left = '5';
            $margin_right = '5';
            $margin_bottom = '105';
            $margin_footer = 2;
            $auto_bottom_margin = false;

         } else {

            // Plantillas sin caso propio (4, 5, 6) de cualquier tipo (factura,
            // pos, soporte, etc.): comparten el mismo diseño de encabezado alto.
            // Sin estos margenes el header se monta sobre el head_note y los
            // datos del cliente (bug reportado en POS 4/5/6).
            $margin_top = '35';
            $margin_left = '5';
            $margin_right = '15';
            $margin_bottom = '5';

         }

        if($template){
            $pdf = new Mpdf([
                'fontDir' => array_merge($fontDirs, [
                    base_path('public/fonts/roboto/'),
                ]),
                'fontdata' => $fontData + [
                    'Roboto' => [
                        'R' => 'Roboto-Regular.ttf',
                        'B' => 'Roboto-Bold.ttf',
                        'I' => 'Roboto-Italic.ttf',
                    ]
                ],
                'default_font' => 'Roboto',
                'margin_left' => $margin_left,
                'margin_right' => $margin_right,
                'margin_top' => $margin_top,
                'margin_bottom' => $margin_bottom ,
                'margin_header' => $margin_header,
                'margin_footer' => $margin_footer,
                'setAutoBottomMargin' => $auto_bottom_margin,
                'format' => isset($format_print) ? $format_print : null ,  // Esta es la línea que se añade para definir el tamaño del papel
                'orientation' => isset($orientation) ? $orientation : 'P',
            ]);
        }
        else{
            $pdf = new Mpdf([
            'fontDir' => array_merge($fontDirs, [
                base_path('public/fonts/roboto/'),
            ]),
            'fontdata' => $fontData + [
                'Roboto' => [
                    'R' => 'Roboto-Regular.ttf',
                    'B' => 'Roboto-Bold.ttf',
                    'I' => 'Roboto-Italic.ttf',
                ]
            ],
            'default_font' => 'Roboto',
            'margin_left' => 5,
            'margin_top' => 35,
            'margin_bottom' => 5,
            'margin_header' => 5,
            'margin_footer' => 2
            ]);
        }
        // No todas las carpetas tienen styles{N}.css para cada plantilla
        // (ej. invoice/styles4.css); esas plantillas siempre se han generado
        // con styles.css, asi que se usa como fallback en vez de fallar.
        $stylesheet = $template
            ? base_path('resources/views/pdfs/' . $type . '/styles'.$template.'.css')
            : base_path('resources/views/pdfs/' . $type . '/styles.css');
        if (!file_exists($stylesheet)) {
            $stylesheet = base_path('resources/views/pdfs/' . $type . '/styles.css');
        }
        if (file_exists($stylesheet)) {
            $pdf->WriteHTML(file_get_contents($stylesheet), HTMLParserMode::HEADER_CSS);
        }
        return $pdf;
    }
    /**
     * Zip Email Payroll
     *
     */
    protected function zipEmailPayroll($xml, $pdf)
    {
        // Usar directamente los parámetros $xml y $pdf que contienen las rutas reales
        $nameXML = preg_replace("/[\r\n|\n|\r]+/", "", $xml);
        $namePDF = preg_replace("/[\r\n|\n|\r]+/", "", $pdf);
        // Derivar el ZIP del PDF (mismo nombre, diferente extensión)
        $nameZip = preg_replace("/[\r\n|\n|\r]+/", "", substr($namePDF, 0, strlen($namePDF) - 3)."zip");

        $zip = new ZipArchive();

        $result_code = $zip->open($nameZip, ZipArchive::CREATE);
        $zip->addFile($nameXML, basename($nameXML));
        $zip->addFile($namePDF, basename($namePDF));

        $zip->close();
        return $nameZip;
    }

    /**
     * Zip Email Event
     *
     */
    protected function zipEmailEvent($xml, $pdf)
    {
//        $nameXML = preg_replace("/[\r\n|\n|\r]+/", "", $xml);
        $namePDF = preg_replace("/[\r\n|\n|\r]+/", "", $pdf);
        $nameXML = preg_replace("/[\r\n|\n|\r]+/", "", substr($pdf, 0, strlen($pdf) - 3)."xml");
        $nameZip = preg_replace("/[\r\n|\n|\r]+/", "", substr($pdf, 0, strlen($pdf) - 3)."zip");

        $zip = new ZipArchive();

        $result_code = $zip->open($nameZip, ZipArchive::CREATE);
        $zip->addFile($nameXML, basename($nameXML));
        $zip->addFile($namePDF, str_replace('xml', 'pdf', basename($nameXML)));

        $zip->close();
        return $nameZip;
    }

    /**
     * Zip Email
     *
     */
    protected function zipEmail($xml, $pdf)
    {
        $nameXML = preg_replace("/[\r\n|\n|\r]+/", "", $xml);
        $namePDF = preg_replace("/[\r\n|\n|\r]+/", "", $pdf);
        $nameZip = preg_replace("/[\r\n|\n|\r]+/", "", substr($xml, 0, strlen($xml) - 3)."zip");
        $nameAD = preg_replace("/[\r\n|\n|\r]+/", "", substr($xml, 0, strlen($xml) - 4));

        $zip = new ZipArchive();

        $result_code = $zip->open($nameZip, ZipArchive::CREATE);
        $zip->addFile($nameXML, basename($nameXML));
        $zip->addFile($namePDF, str_replace('xml', 'pdf', basename($nameXML)));

        $R = substr($nameAD, 0, strlen($nameAD) - strlen(basename($nameAD)));
        $listado = glob($R.'anx-*'.basename($nameAD).'.*');
        foreach($listado as $elemento) {
            $zip->addFile($elemento, basename($elemento));
        }

        $zip->close();
        return $nameZip;
    }

    /**
     * Zip base64.
     *
     * @param \App\Company              $company
     * @param \App\Resolution           $resolution
     * @param \Stenfrank\UBL21dian\Sign $sign
     *
     * @return string
     */
    protected function zipBase64(Company $company, Resolution $resolution, Sign $sign, $GuardarEn = false, $batch = false, $ret_array = false)
    {
        StorageService::setCompany($company);
        $dir = preg_replace("/[\r\n|\n|\r]+/", "", "zip/{$resolution->company_id}");
        $nameXML = preg_replace("/[\r\n|\n|\r]+/", "", $this->getFileName($company, $resolution));
        if ($batch) {
            $nameZip = $batch . ".zip";
        } else {
            $uniqueId = uniqid();
            $nameZip = preg_replace("/[\r\n|\n|\r]+/", "", $this->getFileName($company, $resolution, 6, "-{$uniqueId}.zip"));
        }

        $this->pathZIP = preg_replace("/[\r\n|\n|\r]+/", "", "app/zip/{$resolution->company_id}/{$nameZip}");

        // Save XML to storage
        StorageService::put(preg_replace("/[\r\n|\n|\r]+/", "", "xml/{$resolution->company_id}/{$nameXML}"), $sign->xml);

        if (!StorageService::has($dir)) {
            StorageService::makeDirectory($dir);
        }

        // For ZipArchive we need local filesystem access
        $localXmlPath = preg_replace("/[\r\n|\n|\r]+/", "", StorageService::tempPath("xml/{$resolution->company_id}/{$nameXML}"));
        $localZipPath = preg_replace("/[\r\n|\n|\r]+/", "", StorageService::tempPath("zip/{$resolution->company_id}/{$nameZip}"));

        // Ensure local XML exists for ZIP creation
        if (StorageService::isS3()) {
            $xmlDir = dirname($localXmlPath);
            if (!is_dir($xmlDir)) { mkdir($xmlDir, 0755, true); }
            file_put_contents($localXmlPath, $sign->xml);
            $zipDir = dirname($localZipPath);
            if (!is_dir($zipDir)) { mkdir($zipDir, 0755, true); }
        }

        $zip = new ZipArchive();

        $result_code = $zip->open($localZipPath, ZipArchive::CREATE);
        if($result_code !== true){
            $zip = new zipfileDIAN();
            $zip->add_file(implode("", file($localXmlPath)), preg_replace("/[\r\n|\n|\r]+/", "", $nameXML));
            $zipContent = $zip->file();
			StorageService::put(preg_replace("/[\r\n|\n|\r]+/", "", "zip/{$resolution->company_id}/{$nameZip}"), $zipContent);
            if (StorageService::isS3()) {
                file_put_contents($localZipPath, $zipContent);
            }
        }
        else{
            $zip->addFile($localXmlPath, preg_replace("/[\r\n|\n|\r]+/", "", $nameXML));
            $zip->close();
            // Upload ZIP to configured storage
            StorageService::putLocalFile(preg_replace("/[\r\n|\n|\r]+/", "", "zip/{$resolution->company_id}/{$nameZip}"), $localZipPath, false);
        }

        if ($GuardarEn){
            copy($localXmlPath, $GuardarEn.".xml");
            copy($localZipPath, $GuardarEn.".zip");
        }

        if($ret_array)
            return[
                'ZipBase64Bytes' => $this->ZipBase64Bytes = base64_encode(file_get_contents($localZipPath)),
                'xml_filename' => $nameXML
            ];
        else
            return $this->ZipBase64Bytes = base64_encode(file_get_contents($localZipPath));
    }

    /**
     * Zip base64.
     *
     * @param \App\Company              $company
     * @param \App\Resolution           $resolution
     * @param \Stenfrank\UBL21dian\Sign $sign
     *
     * @return string
     */
    protected function zipBase64SendEvent(Company $company, $codeevent, $identificationnumber, $prefixnumberdoc, Sign $sign, $GuardarEn = false)
    {
        StorageService::setCompany($company);
        $dir = preg_replace("/[\r\n|\n|\r]+/", "", "zip/{$company->id}");
        $nameXML = preg_replace("/[\r\n|\n|\r]+/", "", $this->getFileNameSendEvent($codeevent, $identificationnumber, $prefixnumberdoc));
        $nameZip = preg_replace("/[\r\n|\n|\r]+/", "", $this->getFileNameSendEvent($codeevent, $identificationnumber, $prefixnumberdoc, '.zip'));
        $GuardarEn = preg_replace("/[\r\n|\n|\r]+/", "", $GuardarEn);
        $this->pathZIP = preg_replace("/[\r\n|\n|\r]+/", "", "app/zip/{$company->id}/{$nameZip}");

        StorageService::put(preg_replace("/[\r\n|\n|\r]+/", "", "xml/{$company->id}/{$nameXML}"), $sign->xml);

        if (!StorageService::has($dir)) {
            StorageService::makeDirectory($dir);
        }

        $localXmlPath = preg_replace("/[\r\n|\n|\r]+/", "", StorageService::tempPath("xml/{$company->id}/{$nameXML}"));
        $localZipPath = preg_replace("/[\r\n|\n|\r]+/", "", StorageService::tempPath("zip/{$company->id}/{$nameZip}"));

        if (StorageService::isS3()) {
            $xmlDir = dirname($localXmlPath);
            if (!is_dir($xmlDir)) { mkdir($xmlDir, 0755, true); }
            file_put_contents($localXmlPath, $sign->xml);
            $zipDir = dirname($localZipPath);
            if (!is_dir($zipDir)) { mkdir($zipDir, 0755, true); }
        }

        $zip = new ZipArchive();

        $result_code = $zip->open($localZipPath, ZipArchive::CREATE);
        if($result_code !== true){
            $zip = new zipfileDIAN();
            $zip->add_file(implode("", file($localXmlPath)), preg_replace("/[\r\n|\n|\r]+/", "", $nameXML));
            $zipContent = $zip->file();
			StorageService::put(preg_replace("/[\r\n|\n|\r]+/", "", "zip/{$company->id}/{$nameZip}"), $zipContent);
            if (StorageService::isS3()) {
                file_put_contents($localZipPath, $zipContent);
            }
        }
        else{
            $zip->addFile($localXmlPath, preg_replace("/[\r\n|\n|\r]+/", "", $nameXML));
            $zip->close();
            StorageService::putLocalFile(preg_replace("/[\r\n|\n|\r]+/", "", "zip/{$company->id}/{$nameZip}"), $localZipPath, false);
        }
        if ($GuardarEn){
            copy($localXmlPath, $GuardarEn.".xml");
            copy($localZipPath, $GuardarEn.".zip");
        }

        return $this->ZipBase64Bytes = base64_encode(file_get_contents($localZipPath));
    }

    /**
     * Zip base64 Send Document XML.
     *
     * @param \App\Company              $company
     * @param \App\Resolution           $resolution
     * @param \Stenfrank\UBL21dian\Sign $sign
     *
     * @return string
     */
    protected function zipBase64SendDocument($passwordcertificate, $identificationnumber, $tipodoc, $documentnumber, Sign $sign, $GuardarEn = false)
    {
        $dir = preg_replace("/[\r\n|\n|\r]+/", "", "zip/{$passwordcertificate}");
        $nameXML = preg_replace("/[\r\n|\n|\r]+/", "", $this->getFileNameSendDocument($identificationnumber, $tipodoc, $documentnumber));
        $nameZip = preg_replace("/[\r\n|\n|\r]+/", "", $this->getFileNameSendDocument($identificationnumber, 'ZIP', $documentnumber, '.zip'));

        $relativeZipPath = preg_replace("/[\r\n|\n|\r]+/", "", "zip/{$passwordcertificate}/{$nameZip}");
        $this->pathZIP = preg_replace("/[\r\n|\n|\r]+/", "", "app/zip/{$passwordcertificate}/{$nameZip}");

        $relativeXmlPath = preg_replace("/[\r\n|\n|\r]+/", "", "xml/{$passwordcertificate}/{$nameXML}");
        StorageService::put($relativeXmlPath, $sign->xml);

        if (!StorageService::has($dir)) {
            StorageService::makeDirectory($dir);
        }

        // Local paths for ZipArchive (needs local filesystem)
        $localXmlPath = StorageService::tempPath($relativeXmlPath);
        $localZipPath = StorageService::tempPath($relativeZipPath);

        // Ensure local temp dirs exist for S3
        if (StorageService::isS3()) {
            @mkdir(dirname($localXmlPath), 0755, true);
            @mkdir(dirname($localZipPath), 0755, true);
            file_put_contents($localXmlPath, $sign->xml);
        }

        $zip = new ZipArchive();

        $result_code = $zip->open($localZipPath, ZipArchive::CREATE);
        if($result_code !== true){
            $zip = new zipfileDIAN();
            $zip->add_file(implode("", file($localXmlPath)), preg_replace("/[\r\n|\n|\r]+/", "", $nameXML));
            StorageService::put($relativeZipPath, $zip->file());
            // Write locally too for base64 read
            if (StorageService::isS3()) {
                file_put_contents($localZipPath, $zip->file());
            }
        }
        else{
            $zip->addFile($localXmlPath, preg_replace("/[\r\n|\n|\r]+/", "", $nameXML));
            $zip->close();
            StorageService::putLocalFile($relativeZipPath, $localZipPath, StorageService::isS3());
        }

        if ($GuardarEn){
            copy($localXmlPath, $GuardarEn.".xml");
            copy($localZipPath, $GuardarEn.".zip");
        }

        return $this->ZipBase64Bytes = base64_encode(file_get_contents($localZipPath));
    }

    /**
     * Get file name.
     *
     * @param \App\Company    $company
     * @param \App\Resolution $resolution
     *
     * @return string
     */
    protected function getFileName(Company $company, Resolution $resolution, $typeDocumentID = null, $extension = '.xml')
    {
        $date = now();
        $prefix = (is_null($typeDocumentID)) ? $resolution->type_document->prefix : TypeDocument::findOrFail($typeDocumentID)->prefix;

        // Reintento ante clave duplicada: en PostgreSQL el SELECT FOR UPDATE no
        // bloquea filas inexistentes (no hay gap-lock como en InnoDB), asi que
        // dos peticiones simultaneas del mismo (year, type_document_id) pueden
        // pasar ambas el firstOrCreate; la segunda choca con el unique de sends
        // y aborta su transaccion. Al reintentar, la fila ya existe y se
        // bloquea normalmente.
        $lastException = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(function() use ($company, $date, $typeDocumentID, $resolution, $prefix, $extension) {

                    $send = $company->send()->lockForUpdate()->firstOrCreate([
                        'year' => $date->format('Y'),
                        'type_document_id' => $typeDocumentID ?? $resolution->type_document_id,
                    ]);

                    $current = $send->next_consecutive ?? 1;

                    $send->next_consecutive = $current + 1;
                    $send->save();

                    return "{$prefix}{$this->stuffedString($company->identification_number)}{$this->ppp}{$date->format('y')}{$this->stuffedString($current, 8)}{$extension}";
                });
            } catch (\Illuminate\Database\QueryException $e) {
                $lastException = $e;
                // 23000 = integridad MySQL, 23505 = unique violation PostgreSQL
                $isDuplicate = in_array((string) $e->getCode(), ['23000', '23505'], true);
                if (!$isDuplicate || $attempt === 3) {
                    break;
                }
            } catch (\Exception $e) {
                $lastException = $e;
                break;
            }
        }

        \Log::error("Error en getFileName: " . $lastException->getMessage());
        throw new \Exception("Error generando nombre de archivo: " . $lastException->getMessage());
    }

    /**
     * Get file name Send Document.
     *
     *
     * @return string
     */
    protected function getFileNameSendDocument($identificationnumber, $tipodoc = null, $documentnumber, $extension = '.xml')
    {
        $date = now();
        if($tipodoc == 'INVOICE')
            $prefix = 'fv';
        else
            if($tipodoc == 'NC')
                $prefix = 'nc';
            else
                if($tipodoc == 'ND')
                    $prefix = 'nd';
                else
                    $prefix = 'z';

        $send = $documentnumber;

        $name = "{$prefix}{$this->stuffedString($identificationnumber)}{$this->ppp}{$date->format('y')}{$this->stuffedString($documentnumber ?? 1, 8)}{$extension}";

        return $name;
    }

    /**
     * Get file name Send Document.
     *
     *
     * @return string
     */
    protected function getFileNameSendEvent($codeevent, $identificationnumber, $documentnumber, $extension = '.xml')
    {
        $date = now();
        $prefix = 'ar';

        $send = $documentnumber;

        $name = "{$prefix}{$codeevent}{$this->stuffedString($identificationnumber)}{$this->ppp}{$date->format('y')}{$this->stuffedString($documentnumber ?? 1, 8)}{$extension}";

        return $name;
    }

    /**
     * Stuffed string.
     *
     * @param string $string
     * @param int    $length
     * @param int    $padString
     * @param int    $padType
     *
     * @return string
     */
    protected function stuffedString($string, $length = 10, $padString = 0, $padType = STR_PAD_LEFT)
    {
        return str_pad($string, $length, $padString, $padType);
    }

    /**
     * Get ZIP.
     *
     * @return string
     */
    protected function getZIP()
    {
        return $this->ZipBase64Bytes;
    }

    /**
     * post sendEmail.
     *
     * @return string
     */
    protected function sendEmail(string $filename, array $data){
        $company    = $data['user'];
        $customer   = $data['customer'];

        $message = Mail::to($customer->email)->send(new InvoiceMail($data, $filename));

        return $message;
    }

    protected function InvoiceByZipKey($company_idnumber, $zipkey){
        $relativePath = 'public/'.$company_idnumber;
        if (StorageService::isS3()) {
            $files = StorageService::disk()->files($relativePath);
            foreach($files as $archivo){
                $basename = basename($archivo);
                if (substr($basename, 0, 7) == "RptaFE-"){
                    $signedxml = StorageService::get($archivo);
                    if(strpos($signedxml, "<b:ZipKey>{$zipkey}</b:ZipKey>") <> false)
                        return substr($basename, strpos($basename, '-') + 1);
                }
            }
        } else {
            $directory = StorageService::tempPath('public/'.$company_idnumber);
            $scanned_directory = array_diff(scandir($directory, SCANDIR_SORT_DESCENDING), array('..', '.'));
            foreach($scanned_directory as $archivo){
                if (substr($archivo, 0, 7) == "RptaFE-"){
                    $signedxml = file_get_contents(StorageService::tempPath("public/".$company_idnumber."/".$archivo));
                    if(strpos($signedxml, "<b:ZipKey>{$zipkey}</b:ZipKey>") <> false)
                        return substr($archivo, strpos($archivo, '-') + 1);
                }
            }
        }
        return false;
    }

    protected function ValueXML($stringXML, $xpath){
        if(substr($xpath, 0, 1) != '/')
            return NULL;
        $search = substr($xpath, 1, strpos(substr($xpath, 1), '/'));
        $posinicio = strpos($stringXML, "<".$search);
        if($posinicio == 0 and $search != 's:Envelope')
           return NULL;
        $posinicio = strpos($stringXML, ">", $posinicio) + 1;
        $posCierre = strpos($stringXML, "</".$search.">", $posinicio);
        if($posCierre == 0)
            return NULL;
        $valorXML = substr($stringXML, $posinicio, $posCierre - $posinicio);
        if(strcmp(substr($xpath, strpos($xpath, $search) + strlen($search)), '/') != 0)
            return $this->ValueXML($valorXML, substr($xpath, strpos($xpath, $search) + strlen($search)));
        else
            return $valorXML;
    }

    protected function readSimpleXML($path){
        $xml = new \SimpleXMLElement(file_get_contents($path));
        return $xml;
    }

    protected function readXML($path){
        $xml = new \SimpleXMLElement(file_get_contents($path));
        $dom = new \DOMDocument('1.0', 'utf-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        return $dom;
    }

    protected function ActualizarTablas(){
        // User
        $user = auth()->user();

        // type regimes

        // antes: where('id', '!=', '') — truco MySQL para "todos" que en
        // PostgreSQL revienta ('' no castea a bigint)
        $typeregime = TypeRegime::get();
        foreach($typeregime as $regime){
            switch($regime->id){
                case '1':
                    $regime->name = 'Responsable de IVA';
                    $regime->code = '48';
                    break;
                case '2':
                    $regime->name = 'No Responsable de IVA';
                    $regime->code = '49';
                    break;
            }
            $regime->save();
        }

        // type liabilities

        $typeliabilities = TypeLiability::where('id', '!=', 7)->where('id', '!=', 9)->where('id', '!=', 14)->where('id', '!=', 112)->where('id', '!=', 117)->get();
        if($typeliabilities != NULL){
            foreach($typeliabilities as $typeliabilitie)
                $typeliabilitie->delete();
        }

        // type operations

        $borrar = TypeOperation::where('id', 1);
        if($borrar != NULL)
            $borrar->delete();
        $borrar = TypeOperation::where('id', 2);
        if($borrar != NULL)
            $borrar->delete();
        $borrar = TypeOperation::where('id', 3);
        if($borrar != NULL)
            $borrar->delete();

        $typeoperation = TypeOperation::where('id', '>=', 4)->where('id', '<=', 12)->get();
        foreach($typeoperation as $operation){
            switch($operation->id){
                case '4':
                    $operation->name = 'Nota Débito para facturación electrónica V1 (Decreto 2242)';
                    $operation->code = '33';
                    break;
                case '5':
                    $operation->name = 'Nota Débito sin referencia a facturas';
                    $operation->code = '32';
                    break;
                case '6':
                    $operation->name = 'Nota Débito que referencia una factura electrónica';
                    $operation->code = '30';
                    break;
                case '7':
                    $operation->name = 'Nota Crédito para facturación electrónica V1 (Decreto 2242)';
                    $operation->code = '23';
                    break;
                case '8':
                    $operation->name = 'Nota Crédito sin referencia a facturas';
                    $operation->code = '22';
                    break;
                case '9':
                    $operation->name = 'AIU';
                    $operation->code = '09';
                    break;
                case '10':
                    $operation->name = 'Estandar';
                    $operation->code = '10';
                    break;
                case '11':
                    $operation->name = 'Mandatos';
                    $operation->code = '11';
                    break;
                case '12':
                    $operation->name = 'Nota Crédito que referencia una factura electrónica';
                    $operation->code = '20';
                    break;
            }
            $operation->save();
        }

        // taxes

        // antes: where('id', '!=', '') — mismo truco MySQL incompatible con PostgreSQL
        $taxes = Tax::get();
        foreach($taxes as $tax){
            switch($tax->id){
                case '1':
                    $tax->description = 'Impuesto sobre la Ventas';
                    break;
                case '2':
                    $tax->description = 'Impuesto al Consumo Departamental';
                    break;
                case '6':
                    $tax->name = 'ReteRenta';
                    break;
            }
            $tax->save();
        }

        // type_documents

        // antes: where('id', '==', '3') — operador invalido que Laravel convertia
        // en id = '==' (en MySQL casteaba a id = 0 y nunca actualizaba; en
        // PostgreSQL revienta). El dato ya viene correcto del CSV de catalogos.
        $type_documents = TypeDocument::where('id', 3)->get();
        foreach($type_documents as $type_document){
            switch($type_document->id){
                case '3':
                    $type_document->cufe_algorithm = 'CUDE-SHA384';
                    break;
            }
            $type_document->save();
        }

        $type_documento = TypeDocument::updateOrCreate(
                            ['id' => 7],
                            ['name' => 'AttachedDocument',
                             'code' => '89',
                             'cufe_algorithm' => '',
                             'prefix' => 'at']
                          );
    }

    protected function validarDigVerifDIAN($nit)
    {
        if(is_numeric(trim($nit))){
            $secuencia = array(3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71);
            $d = str_split(trim($nit));
            krsort($d);
            $cont = 0;
            unset($val);
            foreach ($d as $key => $value) {
                $val[$cont] = $value * $secuencia[$cont];
                $cont++;
            }
            $suma = array_sum($val);
            $div = intval($suma / 11);
            $num = $div * 11;
            $resta = $suma - $num;
            if ($resta == 1)
                return $resta;
            else
                if($resta != 0)
                    return 11 - $resta;
                else
                    return $resta;
        } else {
            return FALSE;
        }
    }

    protected function saveAnnexes($annexes, $filename)
    {
        $company = auth()->user()->company;
        $i = 0;
        foreach($annexes as $document){
            $i++;
            $document_filename = "anx-{$i}-{$filename}.{$document['extension']}";
            if (base64_decode($document['document'], true)) {
                StorageService::put("public/{$company->identification_number}/{$document_filename}", base64_decode($document['document']));
            }
        }
    }

    protected function debugTofile($variable)
    {
        $file = fopen(storage_path("DEBUG.TXT"), "a+");
        fwrite($file, \Carbon\Carbon::now()->format('Y-m-d H:i'));
        fwrite($file, ' --> '.json_encode($variable));
        fwrite($file, PHP_EOL);
        fwrite($file, PHP_EOL);
        fclose($file);
    }

    protected function split_name($name){
        $name = strtoupper($name);
        if(strpos($name, " DE LA "))
            $name = str_replace(" DE LA ", " DE_LA_", $name);
        if(strpos($name, " DE "))
            $name = str_replace(" DE ", " DE_", $name);
        return explode(' ', $name);
    }

    protected function buildImageDataUriFromFile(string $filename): ?string
    {
        if (!is_file($filename) || !is_readable($filename)) {
            return null;
        }

        $binary = @file_get_contents($filename);
        if ($binary === false || $binary === '') {
            return null;
        }

        $imageInfo = @getimagesizefromstring($binary);
        if ($imageInfo === false || empty($imageInfo['mime'])) {
            return null;
        }

        $mime = $imageInfo['mime'];
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($binary);
    }

    public function storeLogo($base64logo)
    {
        try {
            if (!base64_decode($base64logo, true)) {
                throw new Exception('The given data was invalid.');
            }
        } catch (Exception $e) {
            return response([
                'message' => $e->getMessage(),
                'errors' => [
                    'logo' => 'The base64 encoding is not valid.',
                ],
            ], 422);

            return response([
                'message' => $e->getMessage(),
                'errors' => [
                    'logo' => $error,
                ],
            ], 422);
        }

        try {
            $company = auth()->user()->company;
            $name = "alternate_{$company->identification_number}{$company->dv}.jpg";
            // Logos alternativos SIEMPRE se guardan localmente (necesarios para generar PDFs)
            StorageService::putLocal("public/{$company->identification_number}/{$name}", base64_decode($base64logo));

            return [
                'success' => true,
                'message' => 'Logo almacenado con éxito',
            ];
        } catch (Exception $e) {
            return response([
                'message' => 'Internal Server Error',
                'payload' => $e->getMessage(),
            ], 500);
        }
    }

    public function qty_docs_period($document_name = "INVOICE"){
        $qty_docs = 0;

        try{
            $company = auth()->user()->company;

            if(!is_null($company->absolut_start_plan_date)){
                if($document_name == "ABSOLUT"){
                    $qty_docs = (Document::where('identification_number', $company->identification_number)->where('state_document_id', 1)->where('type_document_id', 11)->where('created_at', '>=', $company->absolut_start_plan_date)->count()) +
                                (Document::where('identification_number', $company->identification_number)->where('state_document_id', 1)->where('type_document_id', 13)->where('created_at', '>=', $company->absolut_start_plan_date)->count()) +
                                (ReceivedDocument::where('customer', $company->identification_number)->where('state_document_id', 1)->where('created_at', '>=', $company->absolut_start_plan_date)->count() + Document::where('identification_number', $company->identification_number)->where('state_document_id', 1)->where('aceptacion', 1)->where('created_at', '>=', $company->absolut_start_plan_date)->count()) +
                                (DocumentPayroll::where('identification_number', $company->identification_number)->where('state_document_id', 1)->where('created_at', '>=', $company->absolut_start_plan_date)->count()) +
                                (Document::where('identification_number', $company->identification_number)->where('state_document_id', 1)->where('type_document_id', 1)->where('created_at', '>=', $company->absolut_start_plan_date)->count()) +
                                (Document::where('identification_number', $company->identification_number)->where('state_document_id', 1)->where('type_document_id', 2)->where('created_at', '>=', $company->absolut_start_plan_date)->count()) +
                                (Document::where('identification_number', $company->identification_number)->where('state_document_id', 1)->where('type_document_id', 3)->where('created_at', '>=', $company->absolut_start_plan_date)->count()) +
                                (Document::where('identification_number', $company->identification_number)->where('state_document_id', 1)->where('type_document_id', 4)->where('created_at', '>=', $company->absolut_start_plan_date)->count()) +
                                (Document::where('identification_number', $company->identification_number)->where('state_document_id', 1)->where('type_document_id', 5)->where('created_at', '>=', $company->absolut_start_plan_date)->count());
                    return $qty_docs;
                }
            }
            else{
                if(!is_null($company->start_plan_date))
                    if($document_name == "INVOICE"){
                        $qty_docs = Document::where('identification_number', $company->identification_number)->where('state_document_id', 1)->where('type_document_id', '>=', '1')->where('type_document_id', '<=', '5')->where('created_at', '>=', $company->start_plan_date)->count();
                        return $qty_docs;
                    }

                if(!is_null($company->start_plan_date2)){
                    if($document_name == "PAYROLL"){
                        $qty_docs = DocumentPayroll::where('identification_number', $company->identification_number)->where('state_document_id', 1)->where('created_at', '>=', $company->start_plan_date2)->count();
                        return $qty_docs;
                    }
                }

                if(!is_null($company->start_plan_date3))
                    if($document_name == "RADIAN"){
                        $qty_docs = ReceivedDocument::where('customer', $company->identification_number)->where('state_document_id', 1)->where('created_at', '>=', $company->start_plan_date3)->count() + Document::where('identification_number', $company->identification_number)->where('state_document_id', 1)->where('aceptacion', 1)->where('created_at', '>=', $company->start_plan_date3)->count();
                        return $qty_docs;
                    }

                if(!is_null($company->start_plan_date4))
                    if($document_name == "SUPPORT DOCUMENT"){
//                        $qty_docs = Document::where('identification_number', $company->identification_number)->where('state_document_id', 1)->where('type_document_id', '11')->orWhere('type_document_id', '13')->where('created_at', '>=', $company->start_plan_date4)->count();
                        $qty_docs = Document::where('identification_number', $company->identification_number)->where('state_document_id', 1)->whereIn('type_document_id', ['11', '13'])->where('created_at', '>=', $company->start_plan_date4)->count();                        return $qty_docs;
                    }
            }

        } catch (Exception $e) {
            return response([
                'message' => 'Internal Server Error',
                'payload' => $e->getMessage(),
            ], 500);
        }

    }

    function file_get_contents_utf8($fn){
        $file = fopen($fn, 'r');
        $data = preg_replace("/[\r\n|\n|\r]+/", "", stream_get_contents($file));
        fclose($file);
        return $data;
    }

    function days_between_dates($date_from, $date_to){
        $date_initial = new DateTime(Carbon::parse($date_from)->format('Y-m-d'));
        $date_final = new DateTime(Carbon::parse($date_to)->format('Y-m-d'));
        $interval = $date_initial->diff($date_final);
        if($interval->invert)
            return $interval->days * (-1);
        else
            return $interval->days;
    }

    function verify_certificate($user = FALSE){
        try {
            $c = new ConfigurationController();
            $certificateEndDate = $c->CertificateEndDate($user);

            // Agregar debugging información
            \Log::info('Certificate verification debug', [
                'user_provided' => $user !== FALSE,
                'certificate_end_date_result' => $certificateEndDate,
                'is_string' => is_string($certificateEndDate),
                'is_empty' => empty($certificateEndDate),
            ]);

            // Validar que la respuesta no sea datos binarios o vacía
            if (empty($certificateEndDate) || !is_string($certificateEndDate)) {
                return [
                    'success' => false,
                    'message' => 'No se pudo obtener la fecha de expiración del certificado',
                    'certificate_days_left' => 0,
                    'debug_info' => [
                        'certificate_result' => $certificateEndDate,
                        'is_string' => is_string($certificateEndDate),
                        'is_empty' => empty($certificateEndDate),
                    ]
                ];
            }

            // Verificar que no contenga datos binarios
            if (preg_match('/[\x00-\x08\x0E-\x1F\x7F-\xFF]/', $certificateEndDate)) {
                return [
                    'success' => false,
                    'message' => 'Error al leer la fecha del certificado - datos corruptos',
                    'certificate_days_left' => 0,
                ];
            }

            $certificate_end_date = new DateTime(Carbon::parse(str_replace("/", "-", $certificateEndDate))->format('Y-m-d'));
            $actual_date = new DateTime(Carbon::now()->format('Y-m-d'));
            $interval = $actual_date->diff($certificate_end_date);
            $certificate_days_left = 0;

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al verificar el certificado: ' . $e->getMessage(),
                'certificate_days_left' => 0,
            ];
        }

        if($interval->days == 0 || $interval->invert == 1)
            return [
                'success' => false,
                'message' => 'El certificado digital ya se encuentra vencido...',
                'expiration_date' => $certificate_end_date,
                'certificate_days_left' => 0,
            ];
        else
            return [
                'success' => true,
                'message' => 'El certificado digital es valido...',
                'expiration_date' => $certificate_end_date,
                'certificate_days_left' => $interval->days,
            ];
    }

    //validar estado de los servicios DIAN
    private function verificarEstadoDIAN($url, $retries = 1)
    {
        $attempt = 0;
        $backoff = 1; // Tiempo inicial de espera en segundos

        do {
            try {
                $client = new \GuzzleHttp\Client();
                $response = $client->request('GET', $url, [
                    'timeout' => 10,
                    'connect_timeout' => 10,
                    'read_timeout' => 10,
                    'verify' => false
                ]);
                // Si el código de estado es 200, la DIAN está disponible
                return $response->getStatusCode() == 200;
            } catch (\GuzzleHttp\Exception\ConnectException $e) {
                $attempt++;
                if ($attempt >= $retries) {
                    return false; // Regresa false si el número de intentos alcanza el máximo
                }
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                return false; // Regresa false en caso de un error en la solicitud
            } catch (\Exception $e) {
                return false; // Regresa false en caso de un error inesperado
            }
            // Esperar un poco antes de intentar nuevamente, usando backoff exponencial
            sleep($backoff);
            $backoff *= 2; // Duplicar el tiempo de espera para el próximo intento
        } while ($attempt < $retries);
        return false; // Regresa false si todos los intentos fallan
    }

    /**
     * Envía los correos de un documento (factura/NC/ND/etc.) y devuelve el estado
     * del envío para exponerlo en el response del API (issue: indicador visual de
     * envío de email). No persiste el motivo del fallo en BD; conserva el
     * comportamiento previo de marcar send_email_success/send_email_date_time al
     * enviar con éxito. Reutilizable por los controllers que usan este trait.
     *
     * @return array|null  null si no se solicitó envío (sendmail != true); en otro caso
     *                      ['sent'=>bool, 'to'=>?string, 'cc'=>array, 'error'=>?string, 'sent_at'=>?string]
     */
    protected function sendDocumentEmails($request, $invoice, $customer, $company, $user, $filename)
    {
        if(!isset($request->sendmail) || !$request->sendmail)
            return null;

        $to = isset($request->email_pos_customer) ? $request->email_pos_customer : ($customer->email ?? null);
        $cc = is_array($request->email_cc_list ?? null) ? array_values($request->email_cc_list) : [];

        $status = ['sent' => false, 'to' => $to, 'cc' => $cc, 'error' => null, 'sent_at' => null];

        // Consumidor final genérico sin email POS explícito: el envío no aplica.
        if(count($invoice) == 0 || ($customer->company->identification_number == '222222222222' && !isset($request->email_pos_customer))){
            $status['error'] = 'not_applicable';
            return $status;
        }

        // Cliente sin correo registrado: se persiste para mostrarlo en la interfaz.
        if(empty($to)){
            $status['error'] = 'customer_no_email';
            try {
                $invoice[0]->send_email_success = 0;
                $invoice[0]->send_email_error = 'customer_no_email';
                $invoice[0]->save();
            } catch (\Exception $ignored) {}
            return $status;
        }

        try{
            Mail::to($to)->send(new InvoiceMail($invoice, $customer, $company, FALSE, FALSE, $filename, TRUE, $request));
            if($request->sendmailtome)
                Mail::to($user->email)->send(new InvoiceMail($invoice, $customer, $company, FALSE, FALSE, $filename, FALSE, $request));
            if($request->email_cc_list){
                foreach($request->email_cc_list as $email)
                    Mail::to($email)->send(new InvoiceMail($invoice, $customer, $company, FALSE, FALSE, $filename, FALSE, $request));
            }
            // El correo se envió: el estado NO debe depender de poder persistir en BD.
            $status['sent'] = true;
            $status['sent_at'] = Carbon::now()->format('Y-m-d H:i');
            try {
                $invoice[0]->send_email_success = 1;
                $invoice[0]->send_email_date_time = $status['sent_at'];
                $invoice[0]->send_email_error = null;
                $invoice[0]->save();
            } catch (\Exception $persistErr) {
                \Log::warning('Correo enviado pero no se pudo persistir el estado (¿falta migración send_email_error?): '.$persistErr->getMessage());
            }
        } catch (\Exception $m) {
            \Log::debug($m->getMessage());
            $status['error'] = $m->getMessage();
            // Persiste el fallo para mostrarlo en la interfaz / auditoría.
            try {
                $invoice[0]->send_email_success = 0;
                $invoice[0]->send_email_error = substr($m->getMessage(), 0, 500);
                $invoice[0]->save();
            } catch (\Exception $ignored) {}
        }

        return $status;
    }
}
