unit Dian.CertLoader;

{$mode delphi}

interface

uses
  Classes,
  SysUtils,
  mormot.core.base,
  mormot.core.text,
  mormot.lib.openssl11;

type
  { Carga un .pfx/.p12 y expone el certificado (Base64, para KeyInfo/WS-Security)
    y la llave privada (PEM, para firmar). Misma idea que el viejo TOpenSSL,
    solo que trabajando en RawUtf8 en vez de OWideString. }
  TDianCertLoader = class
  public
    X509Base64: RawUtf8;
    PrivateKeyPem: RawUtf8;
    constructor Create(const APfxPath: TFileName; const APassword: RawUtf8);
  end;

function DianOpenSslReady: Boolean;

implementation

function DianOpenSslReady: Boolean;
begin
  result := mormot.lib.openssl11.OpenSslIsLoaded;
end;

constructor TDianCertLoader.Create(const APfxPath: TFileName; const APassword: RawUtf8);
var
  Stream: TFileStream;
  Der: RawByteString;
  Pkcs12: PPKCS12;
  PrivateKey: PPEVP_PKEY;
  Cert: PX509;
  CertDer: RawByteString;
begin
  inherited Create;
  if not DianOpenSslReady then
    raise Exception.Create('OpenSSL no esta cargado - revisa libcrypto/libssl');

  Stream := TFileStream.Create(APfxPath, fmOpenRead);
  try
    Der := StreamToRawByteString(Stream);
  finally
    Stream.Free;
  end;

  Pkcs12 := LoadPkcs12(Der);
  if not Pkcs12.Extract(APassword, @PrivateKey, @Cert, nil) then
    raise Exception.Create('No se pudo extraer certificado/llave del PFX (clave incorrecta?)');
  try
    CertDer := PX509(Cert)^.ToBinary;
    // BinToBase64L reparte en lineas de 64 chars con salto - formato exigido
    // por WS-Security BinarySecurityToken y por ds:X509Certificate
    X509Base64 := BinToBase64(pointer(CertDer), Length(CertDer));
    PrivateKeyPem := PEVP_PKEY(PrivateKey)^.PrivateToPem('');
  finally
    PEVP_PKEY(PrivateKey)^.Free;
    PX509(Cert)^.Free;
  end;
end;

end.
