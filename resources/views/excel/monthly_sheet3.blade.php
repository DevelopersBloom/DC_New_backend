{{--<!DOCTYPE html>--}}
{{--<html lang="en">--}}
{{--<head>--}}
{{--    <meta charset="UTF-8">--}}
{{--    <meta name="viewport" content="width=device-width, initial-scale=1.0">--}}
{{--    <title>Monthly Export</title>--}}
{{--</head>--}}
{{--<body>--}}
{{--<div id="root"></div>--}}
{{--<!-- You can keep this div as a placeholder for React to mount into -->--}}
{{--</body>--}}
{{--</html>--}}


<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Sheet3</title>
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
        <td><i>Ձ- թիվ 1</i></td>
    </tr>
    <tr>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td><i>(ամսական)</i></td>
    </tr>
    <tr>
        <td></td>
        <td></td>
        <td colspan="4"><strong>ՀԱՇՎԵՏՎՈՒԹՅՈՒՆ ՎԱՐԿԱՅԻՆ ՄԻՋՈՑՆԵՐԻ ԸՆԴՀԱՆՈՒՐ ԾԱՎԱԼԻ,ՏԵՂԱԲԱՇԽՎԱԾ ՎԱՐԿԵՐԻ,ԳՐԱՎԻ ԱՌԱՐԿԱՆԵՐԻ
                ԵՎԻ ՊԱՀ ԸՆԴՈՒՆԱԾ ԳՈՒՅՔԻ ԳՆԱՀԱՏՎԱԾ ԱՐԺԵՔԻ,ՆԵՐԳՐՎԱԾ ՄԻՋՈՑՆԵՐԻ՝ ՅՈՒՐԱՔԱՆՉՅՈՒՐ ՕՐՎԱ ՎԵՐՋԻ ԴՐՈՒԹՅԱՄԲ ՄՆԱՑՈՐԴԻ ՎԵՐԱԲԵՐՅԱԼ</strong></td>
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
        <td colspan="2">(Գրավատան անվանումը)</td>
        <td colspan="2" style="border-bottom: 1px dotted #000000">{{$company_name}}</td>

    </tr>
    <tr></tr>
    <tr></tr>
    <tr>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td>Ամսաիվը</td>
        <td style="border-bottom: 1px dotted #000000">{{$date}}</td>
    </tr>
    <tr></tr>
    <tr></tr>
    <tr>
        <td></td>
        <td style="border-top: 1px solid #000000;border-left: 1px solid #000000;">Հաշվետու ամսվա հերթական օրը</td>
        <td style="border-top: 1px solid #000000;">Գրավի առարկաների գնահատված արժեքը</td>
        <td style="border-top: 1px solid #000000;">Տրամադրված վարկերի ընդհանուր գումարը</td>
        <td style="border-top: 1px solid #000000;">Ի պահ ընդունած առարկաների արժեքը</td>
        <td style="border-top: 1px solid #000000;">Ապահովագրական գումարի չափը</td>
        <td style="border-top: 1px solid #000000;">Դրամական միջոցների ընդհանուր ծավալը</td>
        <td style="border-top: 1px solid #000000;border-right: 1px solid #000000;">Ներգավված միջոցների ընդանուր ծավալը</td>
    </tr>
    <tr>
        <td></td>
        <td style="background: #C0C0C0"><i>1</i></td>
        <td style="background: #C0C0C0"><i>2</i></td>
        <td style="background: #C0C0C0"><i>3</i></td>
        <td style="background: #C0C0C0"><i>4</i></td>
        <td style="background: #C0C0C0"><i>5</i></td>
        <td style="background: #C0C0C0"><i>6</i></td>
        <td style="background: #C0C0C0"><i>7</i></td>
    </tr>
    @for ($i = 1; $i <= 31; $i++)
        <tr>
            <td></td>
            <td>{{$i}}</td>
            @if(array_key_exists($i,$data))
                <td>{{$data[$i]['worth']}}</td>
                <td>{{$data[$i]['given']}}</td>
                <td>0</td>
                <td>{{$data[$i]['insurance']}}</td>
                <td>{{$data[$i]['cashbox_sum']}}</td>
                <td>{{$data[$i]['funds']}}</td>
            @endif
        </tr>
    @endfor
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
