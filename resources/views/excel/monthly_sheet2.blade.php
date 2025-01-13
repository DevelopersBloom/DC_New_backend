<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Sheet2</title>
</head>
<body>
<table>
    <tbody>
    <tr>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
    <tr>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
    <tr></tr>
    <tr></tr>
    <tr>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>

    </tr>
    <tr></tr>
    <tr></tr>
    <tr>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
    </tr>
    <tr></tr>
    <tr></tr>
    <tr>
        <td></td>
        <td>հ/հ</td>
        <td colspan="4">Ցուցանիշների անվանումը</td>
        <td>Նախորդ ժամանակաշրջանում</td>
        <td>Հաշվետու ժամանակաշրջանում</td>
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
    <tr></tr>
    <tr></tr>
    <tr></tr>
    <tr>
        <td></td>
        <td colspan="2">Հանձնման ամսաթիվը</td>
        <td style="border-bottom: 1px dotted #000000">{{$date_given}}</td>
        <td></td>
        <td colspan="2">Գրավատան ղեկավար</td>
        <td style="border-bottom: 1px dotted #000000">{{$representative}}</td>
    </tr>
    <tr>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td>(անուն,ազգանուն)</td>
    </tr>
    <tr></tr>
    <tr>
        <td></td>
        <td colspan="2">Կ.Տ</td>
        <td></td>
        <td></td>
        <td colspan="2">Գլխավոր հաշվապահ</td>
        <td style="border-bottom: 1px dotted #000000"></td>
    </tr>
    <tr>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td>(անուն,ազգանուն)</td>
    </tr>
    </tbody>
</table>

</body>
</html>

