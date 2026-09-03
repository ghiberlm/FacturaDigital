unit Dian.CryptoUtils;

{$mode delphi}

interface

uses
  mormot.core.base,
  mormot.core.text,
  mormot.core.datetime,
  mormot.crypt.core;

{ Quita CR/LF de un campo antes de usarlo en el calculo del identificador
  (CUFE/CUDE/CUDS/CUNE) o de escribirlo como texto en el XML. Mismo motivo
  que el preg_replace que se repite en las plantillas PHP de referencia:
  un salto de linea invisible en un campo (copiado/pegado, importado de
  una BD vieja) cambia el resultado del hash sin que se note - el CUFE
  queda "calculado" pero no coincide con lo que la DIAN espera. Aplicar
  esto a TODO campo que entre en CalcularIdentificador es lo que importa
  de verdad; aplicarlo tambien al escribir en el XML es solo higiene. }
function SanearTexto(const Texto: RawUtf8): RawUtf8;

{ Hash SHA-256/384 de una cadena UTF-8.
  isBinary=True -> devuelve el hash en Base64 (uso: DigestValue de XML-DSig)
  isBinary=False -> devuelve el hash en hexadecimal (uso: depuracion/log) }
function Hash256(const Data: RawUtf8; IsBinary: Boolean = True): RawUtf8;
function Hash384(const Data: RawUtf8; IsBinary: Boolean = True): RawUtf8;

{ Identificador unico para atributos wsu:Id / Id (SignedInfo, KeyInfo, etc).
  Reemplaza al viejo GenerateRandomSHA1 que leia de un archivo compartido:
  ahora es puro en memoria, sin IO. }
function NewId(const Prefix: RawUtf8): RawUtf8;

{ Fecha/hora actual en ISO-8601 UTC, formato que exige WS-Security/XAdES. }
function NowISO: RawUtf8;

{ Igual que NowISO pero con el offset en segundos indicado (para wsu:Expires) }
function NowISOPlusSeconds(SecondsAhead: Integer): RawUtf8;

implementation

function SanearTexto(const Texto: RawUtf8): RawUtf8;
var
  i, j: Integer;
begin
  SetLength(result, Length(Texto));
  j := 0;
  for i := 1 to Length(Texto) do
    if not (Texto[i] in [#10, #13]) then
    begin
      Inc(j);
      result[j] := Texto[i];
    end;
  SetLength(result, j);
end;

function Hash256(const Data: RawUtf8; IsBinary: Boolean): RawUtf8;
var
  SHA: TSha256;
  Digest: TSha256Digest;
begin
  SHA.Full(pointer(Data), Length(Data), Digest);
  if IsBinary then
    result := BinToBase64(@Digest, SizeOf(Digest))
  else
    result := Sha256DigestToString(Digest);
end;

function Hash384(const Data: RawUtf8; IsBinary: Boolean): RawUtf8;
var
  SHA: TSha384;
  Digest: TSha384Digest;
begin
  SHA.Full(pointer(Data), Length(Data), Digest);
  if IsBinary then
    result := BinToBase64(@Digest, SizeOf(Digest))
  else
    result := Sha384DigestToString(Digest);
end;

function NewId(const Prefix: RawUtf8): RawUtf8;
begin
  // random64 + prefijo: unico por llamada, sin depender de nada en disco
  result := UpperCase(Prefix + '-' + Int64ToHex(Random64));
end;

function NowISO: RawUtf8;
begin
  result := DateTimeToIso8601(NowUtc, True) + 'Z';
end;

function NowISOPlusSeconds(SecondsAhead: Integer): RawUtf8;
begin
  result := DateTimeToIso8601(NowUtc + (SecondsAhead / SecsPerDay), True) + 'Z';
end;

end.
