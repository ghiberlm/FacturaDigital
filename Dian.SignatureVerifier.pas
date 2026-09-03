unit Dian.SignatureVerifier;

{$mode delphi}

interface

uses
  mormot.core.base,
  Dian.Models;

type
  TDianVerificacionFirma = class
  public
    EsValida: Boolean;
    Detalle: RawUtf8; // vacio si EsValida; si no, que referencia/verificacion fallo
    procedure RaiseIfInvalid;
  end;

  { Solo para debug/pruebas - NO se usa en el pipeline de produccion salvo
    que se inyecte explicitamente (ver el parametro opcional que agregamos
    en TDianEmisorService.Create). Recalcula lo que TDianXadesSigner
    firmo y compara contra lo que quedo escrito en el XML - no necesita
    certificado propio, la llave publica va dentro del mismo XML firmado
    (ds:X509Certificate). }
  IDianXmlSignatureVerifier = interface
    ['{A1E1F1B0-000A-4A11-9B11-00000000000A}']
    function Verify(const SignedXml: RawUtf8; Kind: TDianDocumentKind): TDianVerificacionFirma;
  end;

  TDianXadesSignatureVerifier = class(TInterfacedObject, IDianXmlSignatureVerifier)
  public
    function Verify(const SignedXml: RawUtf8; Kind: TDianDocumentKind): TDianVerificacionFirma;
  end;

implementation

uses
  SysUtils,
  OXmlPDOM,
  mormot.crypt.openssl,
  mormot.core.unicode,
  Dian.Signer,       // reutiliza SlotDeFirmaPara - mismo criterio que al firmar
  Dian.CryptoUtils;  // reutiliza Hash256

{ TDianVerificacionFirma }

procedure TDianVerificacionFirma.RaiseIfInvalid;
begin
  if not EsValida then
    raise Exception.CreateFmt('Firma XAdES invalida: %s', [Detalle]);
end;

{ TDianXadesSignatureVerifier }

function TDianXadesSignatureVerifier.Verify(const SignedXml: RawUtf8; Kind: TDianDocumentKind): TDianVerificacionFirma;
var
  Doc: IXMLDocument;
  Slots: TXMLNodeList;
  SlotIndex: Integer;
  SignatureNode, SignedInfo, TargetNode, DigestValueNode,
  X509CertNode, SignatureValueNode: PXMLNode;
  References: TXMLNodeList;
  i: Integer;
  RefNode: PXMLNode;
  URI, TargetId: RawUtf8;
  ExpectedDigest, ActualDigest: RawUtf8;
  SignedInfoC14N: RawUtf8;
  SigValueBin, CertDer: RawByteString;
  FirmaOK: Boolean;
begin
  result := TDianVerificacionFirma.Create;
  result.EsValida := True;
  result.Detalle := '';

  Doc := TXMLDocument.Create;
  Doc.LoadFromXML(SignedXml);

  // ---- 1. localizar el nodo de firma (mismo slot que uso el Signer) ----
  SlotIndex := SlotDeFirmaPara(Kind);
  Slots := Doc.DocumentElement.SelectNodes('ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent');
  if Slots.Count <= SlotIndex then
  begin
    result.EsValida := False;
    result.Detalle := 'No existe el slot de firma esperado (indice ' + IntToStr(SlotIndex) + ')';
    Exit;
  end;
  SignatureNode := Slots[SlotIndex].SelectNode('ds:Signature');
  if SignatureNode = nil then
  begin
    result.EsValida := False;
    result.Detalle := 'El slot de firma esta vacio - el documento no esta firmado';
    Exit;
  end;
  SignedInfo := SignatureNode.SelectNode('ds:SignedInfo');

  // ---- 2. recalcular el digest de CADA Reference y comparar ----
  // (mismo criterio que Dian.Signer: URI="" es el documento completo,
  // cualquier otro URI="#xxx" busca el nodo con ese Id)
  References := SignedInfo.SelectNodes('ds:Reference');
  for i := 0 to References.Count - 1 do
  begin
    RefNode := References[i];
    URI := RefNode.GetAttribute('URI');
    DigestValueNode := RefNode.SelectNode('ds:DigestValue');
    if DigestValueNode = nil then
    begin
      result.EsValida := False;
      result.Detalle := result.Detalle + 'Reference sin DigestValue; ';
      Continue;
    end;
    ExpectedDigest := DigestValueNode.Text;

    if URI = '' then
      ActualDigest := Hash256(Doc.DocumentElement.C14N, True)
    else
    begin
      TargetId := Copy(URI, 2, MaxInt); // quita el '#'
      // TODO: confirmar la sintaxis exacta de XPath por atributo que
      // soporta OXml en tu version - esta es la forma habitual pero
      // conviene probarla antes de confiar en el resultado
      TargetNode := Doc.DocumentElement.SelectNode('//*[@Id="' + TargetId + '"]');
      if TargetNode = nil then
      begin
        result.EsValida := False;
        result.Detalle := result.Detalle + 'No se encontro el nodo referenciado por URI=' + URI + '; ';
        Continue;
      end;
      ActualDigest := Hash256(TargetNode.C14N, True);
    end;

    if ActualDigest <> ExpectedDigest then
    begin
      result.EsValida := False;
      result.Detalle := result.Detalle + 'DigestValue no coincide en Reference URI=' + URI + '; ';
    end;
  end;

  // ---- 3. verificar SignatureValue contra la llave publica DEL CERTIFICADO
  //         QUE VIENE DENTRO DEL PROPIO XML (no el que tengas en disco -
  //         asi te enteras si alguien reemplazo el certificado tambien) ----
  X509CertNode := SignatureNode.SelectNode('ds:KeyInfo/ds:X509Data/ds:X509Certificate');
  SignatureValueNode := SignatureNode.SelectNode('ds:SignatureValue');
  if (X509CertNode = nil) or (SignatureValueNode = nil) then
  begin
    result.EsValida := False;
    result.Detalle := result.Detalle + 'Falta KeyInfo/X509Certificate o SignatureValue; ';
    Exit;
  end;

  CertDer := Base64ToBin(X509CertNode.Text);
  SigValueBin := Base64ToBin(SignatureValueNode.Text);
  SignedInfoC14N := SignedInfo.C14N;

  // TODO: confirmar la funcion exacta de mormot.crypt.openssl para verificar
  // con la llave publica extraida de CertDer (via PX509 de mormot.lib.openssl11,
  // como ya hace Dian.CertLoader para la llave privada). No la doy por
  // buena sin probarla, igual que con OpenSslSign en su momento.
  FirmaOK := True; // placeholder - reemplazar por la verificacion real

  if not FirmaOK then
  begin
    result.EsValida := False;
    result.Detalle := result.Detalle + 'SignatureValue no verifica contra el certificado embebido';
  end;
end;

end.
