unit Dian.Sender;

{$mode delphi}

interface

uses
  mormot.core.base,
  Dian.Models,
  Dian.EndpointRegistry,
  Dian.CertLoader;

type
  TDianEstado = (deEnProceso, deAceptado, deRechazado, deError);

  { RespuestaCruda queda generico a proposito (no "XmlRespuesta"): el dia
    que exista un proveedor que responda JSON en vez de XML, esta misma
    clase le sirve sin cambiar nada - el llamador no deberia tener que
    saber el formato para leer Estado/TrackId.
    Proveedor/Codigo/Mensaje/Identificador quedan como campos propios
    (no parte de Mensajes/RespuestaCruda) para que el llamador pueda leer
    lo esencial de la respuesta de CUALQUIER proveedor sin tener que
    parsear su formato propietario - cada implementacion de IDianSender/
    IDianJsonSender llena estos campos al traducir su respuesta nativa. }
  TDianResponse = class
  public
    Proveedor: RawUtf8;      // 'DIAN', 'ProveedorEmision', etc - quien respondio
    Estado: TDianEstado;
    Codigo: RawUtf8;         // codigo propio del proveedor (ej. '00' DIAN, '200' HTTP)
    Mensaje: RawUtf8;        // mensaje principal, ya traducido a texto plano
    Identificador: RawUtf8;  // CUFE/CUDE/CUDS/CUNE/CUDEEVENT del documento, si aplica
    TrackId: RawUtf8;
    RespuestaCruda: RawUtf8;
    Mensajes: TRawUtf8DynArray;
  end;

  { Contrato generico: cualquier proveedor tecnologico (DIAN directa,
    Proveedor Emision, The Factory, DisPapeles...) lo implementa a su
    manera. TDianSoapSender de aqui abajo es LA IMPLEMENTACION PARA DIAN
    DIRECTA especificamente (XML + zip + SOAP + WS-Security) - un proveedor
    que hable JSON sin firma seria otra clase que implementa esta misma
    interfaz, con Send/GetStatus armando un POST JSON en vez de un sobre
    SOAP. Nada de lo que llama a IDianSender necesita saber la diferencia. }
  IDianSender = interface
    ['{A1E1F1B0-0003-4A11-9B11-000000000003}']
    function Send(const SignedXml: RawUtf8; Kind: TDianDocumentKind; Async: Boolean = True): TDianResponse;
    function GetStatus(const TrackId: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
  end;

  { Implementacion para DIAN directa. Arma el zip, arma el sobre SOAP,
    FIRMA EL SOBRE (WS-Security - firma distinta de la XAdES que ya firmo
    el documento), lo envia y devuelve la respuesta. }
  TDianSoapSender = class(TInterfacedObject, IDianSender)
  private
    fEndpoints: IDianEndpointRegistry;
    fCert: TDianCertLoader;

    { Arma <soap:Envelope> completo (Header con Security+Signature, Body
      con OperationBodyXml) y lo firma. OperationBodyXml es SOLO el
      contenido de <soap:Body> - lo arma cada operacion (Send/GetStatus). }
    function ArmarYFirmarSobre(const ToUrl, Action, OperationBodyXml: RawUtf8): RawUtf8;

    { POST del sobre ya firmado contra ToUrl. Devuelve el cuerpo crudo de
      la respuesta - el parseo a TDianResponse lo hace cada operacion. }
    function EnviarSobre(const ToUrl, SoapAction, SobreXml: RawUtf8): RawUtf8;
  public
    constructor Create(AEndpoints: IDianEndpointRegistry; const ACertPath, ACertPassword: RawUtf8);
    destructor Destroy; override;
    function Send(const SignedXml: RawUtf8; Kind: TDianDocumentKind; Async: Boolean = True): TDianResponse;
    function GetStatus(const TrackId: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
  end;

implementation

uses
  SysUtils,
  OXmlPDOM,
  mormot.crypt.openssl,
  mormot.net.client,
  Dian.CryptoUtils,
  Dian.LibXml2;

const
  _ADDRESSING: RawUtf8            = 'http://www.w3.org/2005/08/addressing';
  _SOAP_ENVELOPE: RawUtf8         = 'http://www.w3.org/2003/05/soap-envelope';
  _DIAN_COLOMBIA: RawUtf8         = 'http://wcf.dian.colombia';
  _XMLDSIG: RawUtf8               = 'http://www.w3.org/2000/09/xmldsig#';
  _WSSE: RawUtf8                  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
  _WSU: RawUtf8                   = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
  // Ojo: aqui SI usamos C14N EXCLUSIVO (no el inclusivo que usa la firma
  // XAdES del documento en Dian.Signer) - es lo que exige WS-Security y lo
  // que ya traia probado tu TSOAP.loadXML viejo. Son dos firmas distintas
  // con reglas de canonicalizacion distintas, a proposito.
  _EXC_C14N: RawUtf8              = 'http://www.w3.org/2001/10/xml-exc-c14n#';
  _RSA_SHA256: RawUtf8            = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
  _SHA256: RawUtf8                = 'http://www.w3.org/2001/04/xmlenc#sha256';
  _X509V3: RawUtf8                = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
  _BASE64BINARY: RawUtf8          = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';
  _ACTION_SENDBILLSYNC: RawUtf8   = 'http://wcf.dian.colombia/IWcfDianCustomerServices/SendBillSync';
  _ACTION_GETSTATUS: RawUtf8      = 'http://wcf.dian.colombia/IWcfDianCustomerServices/GetStatus';

constructor TDianSoapSender.Create(AEndpoints: IDianEndpointRegistry; const ACertPath, ACertPassword: RawUtf8);
begin
  inherited Create;
  fEndpoints := AEndpoints;
  fCert := TDianCertLoader.Create(ACertPath, ACertPassword);
end;

destructor TDianSoapSender.Destroy;
begin
  fCert.Free;
  inherited;
end;

function TDianSoapSender.ArmarYFirmarSobre(const ToUrl, Action, OperationBodyXml: RawUtf8): RawUtf8;
var
  Doc: IXMLDocument;
  Envelope, Header, Security, ActionNode, ToNode,
  TimeStamp, Created, Expires, Token,
  SignatureNode, SignedInfo, CanonMethod, SigMethod,
  Reference, Transforms, Transform, DigestMethod, DigestValue,
  SignatureValueNode, KeyInfo, SecTokenRef, TokenRef,
  Body, ToClone: PXMLNode;
  BinTokenId, SecTokenRefId, SignatureId, TimestampId, KeyInfoId, ToId: RawUtf8;
  DigestValueB64, SignatureValueB64, SignedInfoCanonical: RawUtf8;
  RawSign: RawByteString;
begin
  BinTokenId    := NewId('BST');
  SecTokenRefId := NewId('STR');
  SignatureId   := NewId('SIG');
  TimestampId   := NewId('TS');
  KeyInfoId     := NewId('KI');
  ToId          := NewId('TO');

  Doc := TXMLDocument.Create;
  Envelope := Doc.CreateElement('soap:Envelope');
  Envelope.SetAttribute('xmlns:soap', _SOAP_ENVELOPE);
  Envelope.SetAttribute('xmlns:wsa', _ADDRESSING);
  Doc.AppendChild(Envelope);

  // ---- Header: Action, To, Security(Timestamp + BinarySecurityToken + Signature) ----
  Header := Doc.CreateElement('soap:Header');
  Envelope.AppendChild(Header);

  ActionNode := Doc.CreateElement('wsa:Action');
  ActionNode.Text := Action;
  Header.AppendChild(ActionNode);

  ToNode := Doc.CreateElement('wsa:To');
  ToNode.Text := ToUrl;
  ToNode.SetAttribute('wsu:Id', ToId);
  ToNode.SetAttribute('xmlns:wsu', _WSU);
  Header.AppendChild(ToNode);

  Security := Doc.CreateElement('wsse:Security');
  Security.SetAttribute('xmlns:wsse', _WSSE);
  Security.SetAttribute('xmlns:wsu', _WSU);
  Header.AppendChild(Security);

  TimeStamp := Doc.CreateElement('wsu:Timestamp');
  TimeStamp.SetAttribute('wsu:Id', TimestampId);
  Security.AppendChild(TimeStamp);
  Created := Doc.CreateElement('wsu:Created');
  Created.Text := NowISO;
  TimeStamp.AppendChild(Created);
  Expires := Doc.CreateElement('wsu:Expires');
  Expires.Text := NowISOPlusSeconds(60);
  TimeStamp.AppendChild(Expires);

  Token := Doc.CreateElement('wsse:BinarySecurityToken');
  Token.Text := fCert.X509Base64;
  Token.SetAttribute('EncodingType', _BASE64BINARY);
  Token.SetAttribute('ValueType', _X509V3);
  Token.SetAttribute('wsu:Id', BinTokenId);
  Token.SetAttribute('xmlns:wsu', _WSU);
  Security.AppendChild(Token);

  // ---- Signature sobre wsa:To (asi lo hace tu TSOAP.loadXML viejo) ----
  SignatureNode := Doc.CreateElement('ds:Signature');
  SignatureNode.SetAttribute('Id', SignatureId);
  SignatureNode.SetAttribute('xmlns:ds', _XMLDSIG);
  Security.AppendChild(SignatureNode);

  SignedInfo := Doc.CreateElement('ds:SignedInfo');
  SignatureNode.AppendChild(SignedInfo);
  CanonMethod := Doc.CreateElement('ds:CanonicalizationMethod');
  CanonMethod.SetAttribute('Algorithm', _EXC_C14N);
  SignedInfo.AppendChild(CanonMethod);
  SigMethod := Doc.CreateElement('ds:SignatureMethod');
  SigMethod.SetAttribute('Algorithm', _RSA_SHA256);
  SignedInfo.AppendChild(SigMethod);

  Reference := Doc.CreateElement('ds:Reference');
  Reference.SetAttribute('URI', '#' + ToId);
  SignedInfo.AppendChild(Reference);
  Transforms := Doc.CreateElement('ds:Transforms');
  Reference.AppendChild(Transforms);
  Transform := Doc.CreateElement('ds:Transform');
  Transform.SetAttribute('Algorithm', _EXC_C14N);
  Transforms.AppendChild(Transform);
  DigestMethod := Doc.CreateElement('ds:DigestMethod');
  DigestMethod.SetAttribute('Algorithm', _SHA256);
  Reference.AppendChild(DigestMethod);

  // digest de wsa:To: se clona suelto y se le declaran los namespaces que
  // heredaria del documento, porque asi canonicaliza un nodo standalone
  ToClone := ToNode.CloneNode(True);
  ToClone.SetAttribute('xmlns:wsa', _ADDRESSING);
  ToClone.SetAttribute('xmlns:wsu', _WSU);
  DigestValueB64 := Hash256(DianC14N(ToClone.Xml, True), True); // True = exc-c14n, WS-Security
  DigestValue := Doc.CreateElement('ds:DigestValue');
  DigestValue.Text := DigestValueB64;
  Reference.AppendChild(DigestValue);

  // firmar el SignedInfo (necesita los mismos namespaces declarados, igual
  // que el digest de arriba, porque tambien se canonicaliza suelto)
  SignedInfo.SetAttribute('xmlns:ds', _XMLDSIG);
  SignedInfo.SetAttribute('xmlns:wsa', _ADDRESSING);
  SignedInfoCanonical := DianC14N(SignedInfo.Xml, True); // True = exc-c14n, WS-Security
  OpenSslSign('SHA256', Pointer(SignedInfoCanonical), Pointer(fCert.PrivateKeyPem),
    Length(SignedInfoCanonical), Length(fCert.PrivateKeyPem), RawSign);
  SignatureValueB64 := BinToBase64(RawSign);
  SignatureValueNode := Doc.CreateElement('ds:SignatureValue');
  SignatureValueNode.Text := SignatureValueB64;
  SignatureNode.AppendChild(SignatureValueNode);

  KeyInfo := Doc.CreateElement('ds:KeyInfo');
  KeyInfo.SetAttribute('Id', KeyInfoId);
  SignatureNode.AppendChild(KeyInfo);
  SecTokenRef := Doc.CreateElement('wsse:SecurityTokenReference');
  SecTokenRef.SetAttribute('wsu:Id', SecTokenRefId);
  KeyInfo.AppendChild(SecTokenRef);
  TokenRef := Doc.CreateElement('wsse:Reference');
  TokenRef.SetAttribute('URI', '#' + BinTokenId);
  TokenRef.SetAttribute('ValueType', _X509V3);
  SecTokenRef.AppendChild(TokenRef);

  // ---- Body: lo que haya armado la operacion (Send/GetStatus) ----
  Body := Doc.CreateElement('soap:Body');
  Envelope.AppendChild(Body);
  Body.LoadXML(OperationBodyXml); // TODO: confirmar metodo exacto de OXml para insertar XML crudo como hijo

  result := Doc.Xml;
end;

function TDianSoapSender.EnviarSobre(const ToUrl, SoapAction, SobreXml: RawUtf8): RawUtf8;
begin
  // TODO: POST del SobreXml contra ToUrl via mormot.net.client, con el
  // header SOAPAction/content-type application/soap+xml correspondiente.
  // La API exacta (THttpClientSocket vs una funcion helper de mas alto
  // nivel) depende de la version de mORMot que tengas - confirmalo contra
  // tu instalacion antes de dar esto por bueno.
  result := '';
end;

function TDianSoapSender.Send(const SignedXml: RawUtf8; Kind: TDianDocumentKind; Async: Boolean): TDianResponse;
var
  Endpoint, OperationBody, ZipBase64, SobreXml, RespuestaCruda: RawUtf8;
begin
  Endpoint := fEndpoints.EndpointFor(Kind);

  // TODO: comprimir SignedXml en zip (mormot.core.zip) y codificarlo en
  // Base64 - por ahora dejo el XML firmado directo, sin comprimir, para
  // no adivinar la API de zip sin confirmarla
  ZipBase64 := BinToBase64(SignedXml);

  OperationBody :=
    '<wcf:SendBillSync xmlns:wcf="' + _DIAN_COLOMBIA + '">' +
    '<wcf:fileName>documento.zip</wcf:fileName>' +
    '<wcf:contentFile>' + ZipBase64 + '</wcf:contentFile>' +
    '</wcf:SendBillSync>';

  SobreXml := ArmarYFirmarSobre(Endpoint, _ACTION_SENDBILLSYNC, OperationBody);
  RespuestaCruda := EnviarSobre(Endpoint, _ACTION_SENDBILLSYNC, SobreXml);

  result := TDianResponse.Create;
  result.Proveedor := 'DIAN';
  result.RespuestaCruda := RespuestaCruda;
  // TODO: parsear RespuestaCruda -> Estado/Codigo/Mensaje/Identificador/TrackId/Mensajes
end;

function TDianSoapSender.GetStatus(const TrackId: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
var
  Endpoint, OperationBody, SobreXml, RespuestaCruda: RawUtf8;
begin
  Endpoint := fEndpoints.EndpointFor(Kind);

  OperationBody :=
    '<wcf:GetStatus xmlns:wcf="' + _DIAN_COLOMBIA + '">' +
    '<wcf:trackId>' + TrackId + '</wcf:trackId>' +
    '</wcf:GetStatus>';

  SobreXml := ArmarYFirmarSobre(Endpoint, _ACTION_GETSTATUS, OperationBody);
  RespuestaCruda := EnviarSobre(Endpoint, _ACTION_GETSTATUS, SobreXml);

  result := TDianResponse.Create;
  result.Proveedor := 'DIAN';
  result.RespuestaCruda := RespuestaCruda;
  // TODO: parsear RespuestaCruda -> Estado/Codigo/Mensaje/Identificador/TrackId/Mensajes
end;

end.
