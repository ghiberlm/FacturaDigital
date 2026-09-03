unit Dian.BuilderRegistry;

{$mode delphi}

interface

uses
  mormot.core.base,
  Dian.Models,
  Dian.XmlBuilder;

type
  { El orquestador solo conoce esta interfaz - no las clases concretas de builder }
  IDianXmlBuilderRegistry = interface
    ['{A1E1F1B0-0004-4A11-9B11-000000000004}']
    procedure RegisterBuilder(Kind: TDianDocumentKind; Builder: IDianXmlBuilder);
    function Resolve(Kind: TDianDocumentKind): IDianXmlBuilder;
  end;

  TDianXmlBuilderRegistry = class(TInterfacedObject, IDianXmlBuilderRegistry)
  private
    fBuilders: array[TDianDocumentKind] of IDianXmlBuilder;
  public
    procedure RegisterBuilder(Kind: TDianDocumentKind; Builder: IDianXmlBuilder);
    function Resolve(Kind: TDianDocumentKind): IDianXmlBuilder;
  end;

implementation

procedure TDianXmlBuilderRegistry.RegisterBuilder(Kind: TDianDocumentKind; Builder: IDianXmlBuilder);
begin
  fBuilders[Kind] := Builder;
end;

function TDianXmlBuilderRegistry.Resolve(Kind: TDianDocumentKind): IDianXmlBuilder;
begin
  result := fBuilders[Kind];
  if result = nil then
    raise Exception.CreateFmt('No hay builder registrado para %d', [Ord(Kind)]);
end;

end.
