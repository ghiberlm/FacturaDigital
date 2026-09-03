program ProbarLibXml2;

{$mode delphi}
{$APPTYPE CONSOLE}

uses
  SysUtils,
  mormot.core.base,
  Dian.LibXml2;

const
  // XML de juguete: namespace declarado en la raiz, para ver que el C14N
  // inclusivo lo arrastra a los hijos y el exclusivo no
  XML_PRUEBA: RawUtf8 =
    '<raiz xmlns:a="urn:ejemplo:a"><a:hijo>contenido</a:hijo></raiz>';

  // XSD de juguete: exige que <persona> tenga <nombre> como unico hijo
  XSD_PRUEBA: RawUtf8 =
    '<?xml version="1.0"?>' +
    '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">' +
    '  <xs:element name="persona">' +
    '    <xs:complexType>' +
    '      <xs:sequence>' +
    '        <xs:element name="nombre" type="xs:string"/>' +
    '      </xs:sequence>' +
    '    </xs:complexType>' +
    '  </xs:element>' +
    '</xs:schema>';

  XML_VALIDO: RawUtf8 = '<persona><nombre>Ana</nombre></persona>';
  XML_INVALIDO: RawUtf8 = '<persona><apellido>Perez</apellido></persona>';

procedure ProbarC14N;
var
  Inclusivo, Exclusivo: RawUtf8;
begin
  WriteLn('=== 1. Canonicalizacion (C14N) ===');
  WriteLn('XML original:');
  WriteLn('  ', XML_PRUEBA);
  try
    Inclusivo := DianC14N(XML_PRUEBA, False);
    WriteLn('C14N inclusivo:');
    WriteLn('  ', Inclusivo);

    Exclusivo := DianC14N(XML_PRUEBA, True);
    WriteLn('C14N exclusivo:');
    WriteLn('  ', Exclusivo);
  except
    on E: Exception do
      WriteLn('ERROR en C14N: ', E.Message);
  end;
  WriteLn;
end;

procedure ProbarValidacionXsd;
var
  XsdPath: TFileName;
  Errores: TRawUtf8DynArray;
  EsValido: Boolean;
  i: Integer;
begin
  WriteLn('=== 2. Validacion contra XSD ===');
  XsdPath := GetTempDir + 'dian_prueba.xsd';
  // DianValidateXsd pide el XSD en disco (el XML se pasa en memoria) -
  // por eso el XSD si se escribe a un archivo temporal, una sola vez
  FileFromString(XSD_PRUEBA, XsdPath);
  try
    WriteLn('--- XML valido ---');
    try
      EsValido := DianValidateXsd(XML_VALIDO, XsdPath, Errores);
      WriteLn('Resultado esperado: True | obtenido: ', EsValido);
      for i := 0 to High(Errores) do
        WriteLn('  error inesperado: ', Errores[i]);
    except
      on E: Exception do
        WriteLn('ERROR: ', E.Message);
    end;
    WriteLn;

    WriteLn('--- XML invalido (falta <nombre>) ---');
    try
      EsValido := DianValidateXsd(XML_INVALIDO, XsdPath, Errores);
      WriteLn('Resultado esperado: False | obtenido: ', EsValido);
      if Length(Errores) = 0 then
        WriteLn('  (ojo: no se capturo ningun mensaje de error - revisar el callback)')
      else
        for i := 0 to High(Errores) do
          WriteLn('  mensaje capturado: ', Errores[i]);
    except
      on E: Exception do
        WriteLn('ERROR: ', E.Message);
    end;
  finally
    DeleteFile(XsdPath);
  end;
  WriteLn;
end;

begin
  try
    ProbarC14N;
    ProbarValidacionXsd;
  except
    on E: Exception do
      WriteLn('EXCEPCION NO MANEJADA: ', E.ClassName, ': ', E.Message);
  end;
  WriteLn('Fin. Presiona ENTER para salir...');
  ReadLn;
end.
