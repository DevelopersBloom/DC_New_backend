<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Sheet1</title>
</head>
<body>
<table>
    <tbody>
    <tr>
        <td></td>
        <td colspan="6">ՀԱՎԵԼՎԱԾ 2</td>
    </tr>
    <tr>
        <td></td>
        <td colspan="6">«Գրավատնային գործունեություն իրականացնող անձանց կողմից ներկայացվող հաշվետվությունները,դրանց ձևերը և լրացման կարգի»</td>
    </tr>
    <tr>
        <td></td>
        <td colspan="6">Ձև թիվ 2</td>
    </tr>
    <tr>
        <td></td>
        <td colspan="6"><i>(եռամսյակային)</i></td>
    </tr>
    <tr>
        <td></td>
        <td colspan="6">ՀԱՇՎԵՏՎՈՒԹՅՈՒՆ</td>
    </tr>
    <tr>
        <td></td>
        <td colspan="6">ՏԵՂԱԲԱՇԽՎԱԾ ՎԱՐԿԵՐԻ ԵՎ ԱՅԼ ԱԿՏԻՎԵՐԻ,ՍԵՓԱԿԱՆ ԵՎ ՆԵՐԳՐԱՎՎԱԾ ՄԻՋՈՑՆԵՐԻ ԵՎ ԱՅԼ ՊԱՐՏԱՎՈՐՈՒՅՈՒՆՆԵՐԻ,ԻՆՉՊԵՍ ՆԱԵՎ ԳՐԱՎԻ ԱՌԱՐԿԱՆԵՐԻ ՎԵՐԱԲԵՐՅԱԼ</td>
    </tr>
    <tr>
        <td></td>
        <td colspan="6"></td>
    </tr>
    <tr>
        <td></td>
        <td></td>
        <td colspan="2">Գրավատան անվանումը</td>
        <td colspan="2" style="border-bottom: 1px dotted #000000">{{$company_name}}</td>
    </tr>
    <tr>
        <td></td>
        <td></td>
        <td></td>
        <td>Ամսաթիվը</td>
        <td style="border-bottom: 1px dotted #000000"></td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
    <tr></tr>
    <tr>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td>(հազար դրամ)</td>
    </tr>
    <tr>
        <td></td>
        <td>հ/հ</td>
        <td colspan="4">Ցուցանիշների անվանումը</td>
        <td>Նախորդ ժամանակաշրջանում</td>
        <td>Հաշվետու ժամանակաշրջանի վերջի օրվա դրությամբ</td>
    </tr>
    <tr>
        <td></td>
        <td><strong>1</strong></td>
        <td colspan="4"><strong>2</strong></td>
        <td><strong>3</strong></td>
        <td><strong>4</strong></td>
    </tr>
    @foreach($data as $item)
        <tr>
            @if(array_key_exists('strong',$item) && $item['strong'])
                <td></td>
                <td><strong>{{$item['index']}}</strong></td>
                <td colspan="4"><strong>{{$item['title']}}</strong></td>
                <td>{{$item['v1']}}</td>
                <td>{{$item['v2']}}</td>
            @else
                <td></td>
                <td>{{$item['index']}}</td>
                <td colspan="4"><i>{{$item['title']}}</i></td>
                <td>{{$item['v1']}}</td>
                <td>{{$item['v2']}}</td>
            @endif

        </tr>
    @endforeach

    </tbody>
</table>

</body>
</html>
