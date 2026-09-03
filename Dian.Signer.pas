unit Dian.Signer;

{$mode delphi}

interface

uses
  mormot.core.base,
  Dian.Models,
  Dian.CertLoader;

type
  { No sabe nada de la estructura interna de cada tipo de documento (nodos,
    campos de negocio): recibe XML, devuelve XML firmado. Kind solo se usa
    para resolver el slot de ext:ExtensionContent donde va la firma, que
    varia segun el tipo (ver SlotDeFirmaPara). La misma instancia sirve
    para todos los tipos. }
  IDianXmlSigner = interface
    ['{A1E1F1B0-0002-4A11-9B11-000000000002}']
    function Sign(const UnsignedXml: RawUtf8; Kind: TDianDocumentKind;
      const CertPath, CertPassword: RawUtf8): RawUtf8;
  end;

  TDianXadesSigner = class(TInterfacedObject, IDianXmlSigner)
  public
    function Sign(const UnsignedXml: RawUtf8; Kind: TDianDocumentKind;
      const CertPath, CertPassword: RawUtf8): RawUtf8;
  end;

{ Indice del ext:ExtensionContent donde va la firma, segun el tipo de
  documento. Confirmado contra la libreria de referencia:
    0 -> nomina / nomina de ajuste / AttachedDocument
    1 -> factura, notas, documento soporte, eventos RADIAN (el default)
  Documentos equivalentes (POS, transporte, SPD, sector salud) usan otros
  indices (2/3/4/ultimo) - se agregan aqui el dia que se implementen, no
  antes, para no adivinar valores que no hemos confirmado. }
function SlotDeFirmaPara(Kind: TDianDocumentKind): Integer;

implementation

uses
  SysUtils,
  OXmlPDOM,
  mormot.crypt.openssl,
  mormot.core.unicode,
  Dian.CryptoUtils,
  Dian.LibXml2;

const
  _XMLDSIG: RawUtf8      = 'http://www.w3.org/2000/09/xmldsig#';
  _XADES: RawUtf8        = 'http://uri.etsi.org/01903/v1.3.2#';
  // C14N INCLUSIVO (no exclusivo) - asi lo usa la libreria PHP de referencia
  // (Stenfrank/ubl21dian), confirmado contra sus pruebas unitarias.
  _C14N: RawUtf8         = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
  _ENVELOPED: RawUtf8    = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
  _RSA_SHA256: RawUtf8   = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
  _SHA256: RawUtf8       = 'http://www.w3.org/2001/04/xmlenc#sha256';
  _SP_TYPE: RawUtf8      = 'http://uri.etsi.org/01903#SignedProperties';
  // politica de firma v2 vigente + su hash, tomados de Stenfrank/ubl21dian
  // (libreria de referencia con pruebas unitarias que validan este valor) -
  // igual conviene reconfirmar contra el Anexo Tecnico si la DIAN publica
  // una v3.
  _POLICY_URL: RawUtf8   = 'https://facturaelectronica.dian.gov.co/politicadefirma/v2/politicadefirmav2.pdf';
  _POLICY_HASH: RawUtf8  = 'dMoMvtcG5aIzgYo0tIsSQeVJBDnUnfSOfBpxXrmor0Y=';

function SlotDeFirmaPara(Kind: TDianDocumentKind): Integer;
begin
  case Kind of
    dkNomina, dkNominaAjuste: result := 0;
  else
    result := 1; // factura, notas, documento soporte, eventos RADIAN
  end;
end;

function TDianXadesSigner.Sign(const UnsignedXml: RawUtf8; Kind: TDianDocumentKind;
  const CertPath, CertPassword: RawUtf8): RawUtf8;
var
  Cert: TDianCertLoader;
  Doc: IXMLDocument;
  Slots: TXMLNodeList;
  SlotIndex: Integer;
  SignatureSlot: PXMLNode;   // ext:ExtensionContent reservado por el builder

  SignatureNode, SignedInfo, CanonMethod, SigMethod,
  Reference1, Transforms1, Transform1, DigestMethod1, DigestValue1,
  ReferenceKeyInfo, DigestMethodKeyInfo, DigestValueKeyInfo,
  Reference2, DigestMethod2, DigestValue2,
  KeyInfo, X509Data, X509Cert,
  ObjectNode, QualifyingProperties, SignedProperties,
  SigSignedProps, SigningTimeNode, SigningCert, CertNode, CertDigestNode,
  CertDigestMethod, CertDigestValue,
  SignaturePolicyId, SigPolicyId, SigPolicyIdInner, SigPolicyHash,
  SigPolicyHashMethod, SigPolicyHashValue,
  SignatureValueNode: PXMLNode;

  SignedInfoId, SignedPropertiesId, SignatureId, KeyInfoId, ReferenceId: RawUtf8;
  SigningTime, CertDigest, SignedPropertiesDigest, SignedInfoDigest: RawUtf8;
  SignedInfoCanonical: RawUtf8;
  RawSign: RawByteString;
  SignatureValueB64: RawUtf8;
begin
  Cert := TDianCertLoader.Create(CertPath, CertPassword);
  try
    Doc := TXMLDocument.Create;
    Doc.LoadFromXML(UnsignedXml);

    // ---- 1. localizar el hueco que dejo el builder ----
    // el indice varia segun el tipo de documento (ver SlotDeFirmaPara)
    SlotIndex := SlotDeFirmaPara(Kind);
    Slots := Doc.DocumentElement.SelectNodes('ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent');
    if Slots.Count <= SlotIndex then
      raise Exception.CreateFmt('El XML no trae el hueco de firma esperado (ext:ExtensionContent #%d)', [SlotIndex]);
    SignatureSlot := Slots[SlotIndex];

    SignedInfoId       := NewId('SI');
    SignedPropertiesId := NewId('SP');
    SignatureId        := NewId('SIG');
    KeyInfoId          := NewId('KI');
    ReferenceId        := NewId('REF');
    SigningTime        := NowISO;

    // ---- 2. esqueleto ds:Signature (namespaces declarados aqui mismo,
    //         igual que TSOAP.loadXML hace con pSecurity) ----
    SignatureNode := Doc.CreateElement('ds:Signature');
    SignatureNode.SetAttribute('Id', SignatureId);
    SignatureNode.SetAttribute('xmlns:ds', _XMLDSIG);

    SignedInfo := Doc.CreateElement('ds:SignedInfo');
    SignedInfo.SetAttribute('Id', SignedInfoId);
    // namespace declarado aqui mismo, mismo motivo que KeyInfo/SignedProperties:
    // se va a canonicalizar como fragmento suelto via DianC14N (libxml2), no
    // dentro del contexto del documento completo de OXml
    SignedInfo.SetAttribute('xmlns:ds', _XMLDSIG);
    SignatureNode.AppendChild(SignedInfo);

    CanonMethod := Doc.CreateElement('ds:CanonicalizationMethod');
    CanonMethod.SetAttribute('Algorithm', _C14N);
    SignedInfo.AppendChild(CanonMethod);

    SigMethod := Doc.CreateElement('ds:SignatureMethod');
    SigMethod.SetAttribute('Algorithm', _RSA_SHA256);
    SignedInfo.AppendChild(SigMethod);

    // Reference 1: TODO el documento (enveloped) - URI="" = "este mismo documento"
    Reference1 := Doc.CreateElement('ds:Reference');
    Reference1.SetAttribute('Id', ReferenceId);
    Reference1.SetAttribute('URI', '');
    SignedInfo.AppendChild(Reference1);

    Transforms1 := Doc.CreateElement('ds:Transforms');
    Reference1.AppendChild(Transforms1);
    Transform1 := Doc.CreateElement('ds:Transform');
    Transform1.SetAttribute('Algorithm', _ENVELOPED);
    Transforms1.AppendChild(Transform1);

    // Reference 2: ds:KeyInfo - protege el certificado de manipulacion
    // (asi lo hace la libreria de referencia; no es estrictamente obligatorio
    // en XAdES-EPES basico, pero es lo que esta probado contra la DIAN)
    ReferenceKeyInfo := Doc.CreateElement('ds:Reference');
    ReferenceKeyInfo.SetAttribute('URI', '#' + KeyInfoId);
    SignedInfo.AppendChild(ReferenceKeyInfo);

    // Reference 3: xades:SignedProperties (obligatoria en XAdES)
    Reference2 := Doc.CreateElement('ds:Reference');
    Reference2.SetAttribute('Type', _SP_TYPE);
    Reference2.SetAttribute('URI', '#' + SignedPropertiesId);
    SignedInfo.AppendChild(Reference2);

    KeyInfo := Doc.CreateElement('ds:KeyInfo');
    KeyInfo.SetAttribute('Id', KeyInfoId);
    // namespace declarado aqui mismo (no heredado) para que KeyInfo.C14N sea
    // auto-contenido sin importar si se canonicaliza suelto o ya insertado -
    // evita el truco de "reemplazar texto" que usa la libreria PHP de referencia
    KeyInfo.SetAttribute('xmlns:ds', _XMLDSIG);
    SignatureNode.AppendChild(KeyInfo);
    X509Data := Doc.CreateElement('ds:X509Data');
    KeyInfo.AppendChild(X509Data);
    X509Cert := Doc.CreateElement('ds:X509Certificate');
    X509Cert.Text := Cert.X509Base64;
    X509Data.AppendChild(X509Cert);

    // digest de KeyInfo -> ReferenceKeyInfo (se puede calcular ya: KeyInfo
    // esta completo y es auto-contenido gracias al xmlns:ds explicito)
    DigestMethodKeyInfo := Doc.CreateElement('ds:DigestMethod');
    DigestMethodKeyInfo.SetAttribute('Algorithm', _SHA256);
    ReferenceKeyInfo.AppendChild(DigestMethodKeyInfo);
    DigestValueKeyInfo := Doc.CreateElement('ds:DigestValue');
    DigestValueKeyInfo.Text := Hash256(DianC14N(KeyInfo.Xml), True);
    ReferenceKeyInfo.AppendChild(DigestValueKeyInfo);

    // ---- 3. ds:Object -> xades:QualifyingProperties -> SignedProperties ----
    ObjectNode := Doc.CreateElement('ds:Object');
    SignatureNode.AppendChild(ObjectNode);

    QualifyingProperties := Doc.CreateElement('xades:QualifyingProperties');
    QualifyingProperties.SetAttribute('xmlns:xades', _XADES);
    QualifyingProperties.SetAttribute('Target', '#' + SignatureId);
    ObjectNode.AppendChild(QualifyingProperties);

    SignedProperties := Doc.CreateElement('xades:SignedProperties');
    SignedProperties.SetAttribute('Id', SignedPropertiesId);
    // mismo motivo que en KeyInfo: namespaces declarados aqui mismo para
    // que el C14N de este subarbol no dependa de estar adjunto al documento
    SignedProperties.SetAttribute('xmlns:xades', _XADES);
    SignedProperties.SetAttribute('xmlns:ds', _XMLDSIG);
    QualifyingProperties.AppendChild(SignedProperties);

    SigSignedProps := Doc.CreateElement('xades:SignedSignatureProperties');
    SignedProperties.AppendChild(SigSignedProps);

    SigningTimeNode := Doc.CreateElement('xades:SigningTime');
    SigningTimeNode.Text := SigningTime;
    SigSignedProps.AppendChild(SigningTimeNode);

    // hash del certificado (obligatorio en SigningCertificate)
    CertDigest := Hash256(Base64ToBin(Cert.X509Base64), True);
    SigningCert := Doc.CreateElement('xades:SigningCertificate');
    SigSignedProps.AppendChild(SigningCert);
    CertNode := Doc.CreateElement('xades:Cert');
    SigningCert.AppendChild(CertNode);
    CertDigestNode := Doc.CreateElement('xades:CertDigest');
    CertNode.AppendChild(CertDigestNode);
    CertDigestMethod := Doc.CreateElement('ds:DigestMethod');
    CertDigestMethod.SetAttribute('Algorithm', _SHA256);
    CertDigestNode.AppendChild(CertDigestMethod);
    CertDigestValue := Doc.CreateElement('ds:DigestValue');
    CertDigestValue.Text := CertDigest;
    CertDigestNode.AppendChild(CertDigestValue);

    // politica de firma exigida por la DIAN (valores pendientes de confirmar - ver TODO)
    SignaturePolicyId := Doc.CreateElement('xades:SignaturePolicyIdentifier');
    SigSignedProps.AppendChild(SignaturePolicyId);
    SigPolicyId := Doc.CreateElement('xades:SignaturePolicyId');
    SignaturePolicyId.AppendChild(SigPolicyId);
    SigPolicyIdInner := Doc.CreateElement('xades:SigPolicyId');
    SigPolicyId.AppendChild(SigPolicyIdInner);
    SigPolicyIdInner.AppendChild(Doc.CreateElement('xades:Identifier')).Text := _POLICY_URL;
    SigPolicyHash := Doc.CreateElement('xades:SigPolicyHash');
    SigPolicyId.AppendChild(SigPolicyHash);
    SigPolicyHashMethod := Doc.CreateElement('ds:DigestMethod');
    SigPolicyHashMethod.SetAttribute('Algorithm', _SHA256);
    SigPolicyHash.AppendChild(SigPolicyHashMethod);
    SigPolicyHashValue := Doc.CreateElement('ds:DigestValue');
    SigPolicyHashValue.Text := _POLICY_HASH; // TODO: llenar antes de produccion
    SigPolicyHash.AppendChild(SigPolicyHashValue);

    // ---- 4. digest de SignedProperties -> Reference2 ----
    SignedPropertiesDigest := Hash256(DianC14N(SignedProperties.Xml), True);
    DigestMethod2 := Doc.CreateElement('ds:DigestMethod');
    DigestMethod2.SetAttribute('Algorithm', _SHA256);
    Reference2.AppendChild(DigestMethod2);
    DigestValue2 := Doc.CreateElement('ds:DigestValue');
    DigestValue2.Text := SignedPropertiesDigest;
    Reference2.AppendChild(DigestValue2);

    // ---- 5. insertar ds:Signature en el hueco y SOLO DESPUES calcular
    //         el digest de Reference1 (tiene que incluir el propio nodo
    //         Signature, sin SignatureValue todavia) ----
    SignatureSlot.AppendChild(SignatureNode);
    SignedInfoDigest := Hash256(DianC14N(Doc.Xml), True);
    DigestMethod1 := Doc.CreateElement('ds:DigestMethod');
    DigestMethod1.SetAttribute('Algorithm', _SHA256);
    Reference1.AppendChild(DigestMethod1);
    DigestValue1 := Doc.CreateElement('ds:DigestValue');
    DigestValue1.Text := SignedInfoDigest;
    Reference1.AppendChild(DigestValue1);

    // ---- 6. firmar el SignedInfo canonicalizado ----
    SignedInfoCanonical := DianC14N(SignedInfo.Xml);
    OpenSslSign('SHA256', Pointer(SignedInfoCanonical), Pointer(Cert.PrivateKeyPem),
      Length(SignedInfoCanonical), Length(Cert.PrivateKeyPem), RawSign);
    SignatureValueB64 := BinToBase64(RawSign);
    SignatureValueNode := Doc.CreateElement('ds:SignatureValue');
    SignatureValueNode.Text := SignatureValueB64;
    SignatureNode.AppendChild(SignatureValueNode);

    result := Doc.Xml;
  finally
    Cert.Free;
  end;
end;

end.
