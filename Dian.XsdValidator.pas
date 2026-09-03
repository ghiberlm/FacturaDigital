unit Dian.XsdValidator;

{$mode delphi}

interface

uses
  SysUtils,
  mormot.core.base,
  Dian.Models,
  Dian.XmlValidator;

type
  { Registro Kind -> ruta del XSD correspondiente. Mismo patron que
    IDianXmlBuilderRegistry/IDianEndpointRegistry - se llena en el
    composition root cuando organices los XSD oficiales en disco. }
  IDianXsdRegistry = interface
    ['{A1E1F1B0-000C-4A11-9B11-00000000000C}']
    procedure RegisterXsd(Kind: TDianDocumentKind; const XsdPath: TFileName);
    function XsdFor(Kind: TDianDocumentKind): TFileName;
  end;

  TDianXsdRegistry = class(TInterfacedObject, IDianXsdRegistry)
  private
    fPaths: array[TDianDocumentKind] of TFileName;
  public
    procedure RegisterXsd(Kind: TDianDocumentKind; const XsdPath: TFileName);
    function XsdFor(Kind: TDianDocumentKind): TFileName;
  end;

  { Valida directo en memoria via Dian.LibXml2 (bindings a libxml2) - ya no
    depende de un proceso externo (xmllint) ni de archivos temporales,
    a diferencia de la version anterior. }
  TDianXsdValidator = class(TInterfacedObject, IDianXmlValidator)
  private
    fXsdRegistry: IDianXsdRegistry;
  public
    constructor Create(AXsdRegistry: IDianXsdRegistry);
    function Validate(const Xml: RawUtf8; Kind: TDianDocumentKind): TDianVerificacionEsquema;
  end;

implementation

uses
  Dian.LibXml2;

{ TDianXsdRegistry }

procedure TDianXsdRegistry.RegisterXsd(Kind: TDianDocumentKind; const XsdPath: TFileName);
begin
  fPaths[Kind] := XsdPath;
end;

function TDianXsdRegistry.XsdFor(Kind: TDianDocumentKind): TFileName;
begin
  result := fPaths[Kind];
  if result = '' then
    raise Exception.CreateFmt('No hay XSD registrado para %d', [Ord(Kind)]);
end;

{ TDianXsdValidator }

constructor TDianXsdValidator.Create(AXsdRegistry: IDianXsdRegistry);
begin
  inherited Create;
  fXsdRegistry := AXsdRegistry;
end;

function TDianXsdValidator.Validate(const Xml: RawUtf8; Kind: TDianDocumentKind): TDianVerificacionEsquema;
var
  XsdPath: TFileName;
begin
  result := TDianVerificacionEsquema.Create;

  XsdPath := fXsdRegistry.XsdFor(Kind);
  if not FileExists(XsdPath) then
  begin
    result.EsValida := False;
    SetLength(result.Errores, 1);
    result.Errores[0] := 'No se encontro el XSD registrado: ' + XsdPath;
    Exit;
  end;

  result.EsValida := DianValidateXsd(Xml, XsdPath, result.Errores);
end;

end.
