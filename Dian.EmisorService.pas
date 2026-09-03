unit Dian.EmisorService;

{$mode delphi}

interface

uses
  mormot.core.base,
  Dian.Models,
  Dian.XmlBuilder,
  Dian.BuilderRegistry,
  Dian.Signer,
  Dian.Sender,
  Dian.ProveedorTecnologico,
  Dian.SignatureVerifier,
  Dian.XmlValidator;

type
  { Implementacion de IDianProveedorTecnologico PARA DIAN DIRECTA. Ya no
    conoce ninguna clase concreta de builder - solo la interfaz del
    registro. Extender con un tipo de documento nuevo (de cualquier
    familia) no toca esta unidad en absoluto.
    fValidator/fVerificadorFirma son OPCIONALES (nil por defecto) - solo
    para debug/pruebas. En produccion se dejan sin pasar y Emitir corre
    igual que siempre, sin ningun costo extra. }
  TDianEmisorService = class(TInterfacedObject, IDianProveedorTecnologico)
  private
    fBuilders: IDianXmlBuilderRegistry;
    fSigner: IDianXmlSigner;
    fSender: IDianSender;
    fCertPath, fCertPassword: RawUtf8;
    fValidator: IDianXmlValidator;
    fVerificadorFirma: IDianXmlSignatureVerifier;
  public
    constructor Create(ABuilders: IDianXmlBuilderRegistry; ASigner: IDianXmlSigner;
      ASender: IDianSender; const ACertPath, ACertPassword: RawUtf8;
      AValidator: IDianXmlValidator = nil; AVerificadorFirma: IDianXmlSignatureVerifier = nil);
    function Emitir(Data: TDianDocumentoBase): TDianResponse;
    function ConsultarEstado(const Referencia: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
  end;

implementation

constructor TDianEmisorService.Create(ABuilders: IDianXmlBuilderRegistry; ASigner: IDianXmlSigner;
  ASender: IDianSender; const ACertPath, ACertPassword: RawUtf8;
  AValidator: IDianXmlValidator; AVerificadorFirma: IDianXmlSignatureVerifier);
begin
  inherited Create;
  fBuilders := ABuilders;
  fSigner := ASigner;
  fSender := ASender;
  fCertPath := ACertPath;
  fCertPassword := ACertPassword;
  fValidator := AValidator;               // queda nil si no se pasa
  fVerificadorFirma := AVerificadorFirma;  // queda nil si no se pasa
end;

function TDianEmisorService.Emitir(Data: TDianDocumentoBase): TDianResponse;
var
  UnsignedXml, SignedXml: RawUtf8;
begin
  UnsignedXml := fBuilders.Resolve(Data.Kind).Build(Data);
  if Assigned(fValidator) then
    fValidator.Validate(UnsignedXml, Data.Kind).RaiseIfInvalid; // solo si se inyecto (debug/pruebas)

  SignedXml := fSigner.Sign(UnsignedXml, Data.Kind, fCertPath, fCertPassword);
  if Assigned(fVerificadorFirma) then
    fVerificadorFirma.Verify(SignedXml, Data.Kind).RaiseIfInvalid; // solo si se inyecto (debug/pruebas)

  result := fSender.Send(SignedXml, Data.Kind);
end;

function TDianEmisorService.ConsultarEstado(const Referencia: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
begin
  result := fSender.GetStatus(Referencia, Kind);
end;

end.
