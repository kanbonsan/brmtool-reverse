<?php
namespace Kanbonsan\Revbrm;
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of revbrm
 *
 * @author dell
 */
class Revbrm {

    /**
     * brmfile データを分解する
     * @param array $brm json_decode されたデータ. 生データ.
     * @return array 各項目を分解した配列 $brm_data と表す
     * 		'info' => ブルベ情報
     * 		'points' => (array) 各ポイント情報
     * 		'cues' => (array) pointsからキューポイントを抜き出したもの
     * 		'exclude' => (array) 除外区間情報
     * 		'display' => (array) ストリートビュー用のパラメーター
     */
    public static function disassemble($brm) {

        $info = array(
            'id' => $brm['id'],
            'brmName' => $brm['brmName'],
            'brmDate' => $brm['brmDate'],
            'brmDistance' => $brm['brmDistance'],
            'brmStartTime' => $brm['brmStartTime'],
            'brmCurrentStartTime' => $brm['brmCurrentStartTime'],
            'encodedPathAlt' => $brm['encodedPathAlt'],
        );
        $points = array();
        $cues = array();

        foreach ($brm['points'] as $idx => $pt) {
            $points[] = $pt;
            if ($pt['cue']) {
                $pt['cue']['ptidx'] = $idx; // キュー情報にポイントのインデックスを追加
                $cues[] = $pt['cue'];
            }
        }

        $exclude = $brm['exclude'];
        $display = $brm['display'];

        return array(
            'info' => $info,
            'points' => $points,
            'cues' => $cues,
            'exclude' => $exclude,
            'display' => $display,
        );
    }

    /**
     * $brm を受け取ってコースを反転し、$brm を返す.
     * 
     * 　やっていること（仕様）
     *      ・ブルベ名 + '-rev' をつける
     *      ・距離、スタート時間等は変更なし
     *      ・トラックの反転（Kanbonsan\Polyline\Polyline::reverse()メソッド
     *      ・キューポイント
     *          - 名称に含まれる交差点記号の変換（┼┬┤├Ｙ）：これらが続くときも一文字目のみ
     *            進路をもとに反対方向の交差点の形を決定. Y字路については難しいので反対方向はT字路とした.
     *          - 進路の変更. 右→左、左→右 のみの変換
     *          - GarminIcon の変更
     *          - ルートの変更. 道の接続は'→'のみに限定して逆さまに変換.
     *          - 備考情報は丸括弧でくくった.
     *      ・除外区間. 開始・終了ポイントの反転.
     *      ・StreetView 用の設定はそのまま
     * 
     * @param array $brm
     * @return array $brm
     */
    public static function reverse($brm) {

        // $brm ( JSONをばらしただけ ) を要素に分ける
        $brm_data = self::disassemble( $brm );
        
        // $brm_data を分解
        $info = $brm_data['info'];
        $points = $brm_data['points'];
        $cues = $brm_data['cues'];
        $exclude = $brm_data['exclude'];
        $display = $brm_data['display'];

        $cnt = count($points);

        // $info
        $info_rev = array(
            'id' => '', // 必ず振り直す
            'brmName' => $info['brmName'] . '-rev',
            'brmDate' => $info['brmDate'], // 変更なし
            'brmDistance' => $info['brmDistance'], // 変更なし
            'brmStartTime' => $info['brmStartTime'], // 変更なし
            'brmCurrentStartTime' => $info['brmCurrentStartTime'], // 変更なし
            'encodedPathAlt' => \Kanbonsan\Polyline\Polyline::reverse($info['encodedPathAlt']),
        );

        // キューポイントの変換
        $cues_rev = array();
        foreach ($cues as $i => $c) {

            $c_prev = $c['idx'] > 1 ? $cues[$i - 1] : false; // $c_prev ゴールから逆順なので前のポイント
            // idx (キューポイントにおける idx 1～n個)
            $idx_rev = count($cues) - $i;
            // type
            switch ($c['type']) {
                case 'start':
                    $type_rev = 'goal';
                    break;
                case 'goal':
                    $type_rev = 'start';
                    break;
                default:
                    $type_rev = $c['type'];
                    break;
            }

            // name / direction -- このプログラムの肝の部分
            $name = $c['name'];
            $direction = $c['direction'];


            if (preg_match('/[┼┬┤├Ｙ]/u', $name, $matches)) {
                $symbol = $matches[0];
            } else {
                $symbol = '';
            }

            if (preg_match('/[右左]|まっすぐ|直進|道なり/u', $direction, $matches)) {
                $dir_chars = $matches[0];
                switch ($dir_chars) {
                    case '右':
                        $dir_meaning = 'R';
                        break;
                    case '左':
                        $dir_meaning = 'L';
                        break;
                    case 'まっすぐ':
                    case '直進':
                    case '道なり':
                        $dir_meaning = 'S';
                        break;
                }
            } else {
                $dir_chars = '';
                $dir_meaning = '';
            }
            // name 変換
            $symbol_rev = '';
            if ($symbol != '') {
                switch ($symbol) {
                    case '┼':
                        $symbol_rev = '┼'; // 十字路は変更なし
                        break;
                    case '┬':
                        switch ($dir_meaning) {
                            case 'R': // T字路つきあたりの右折
                                $symbol_rev = '┤';
                                break;
                            case 'L': // T字路つきあたりの左折
                                $symbol_rev = '├';
                                break;
                            default:
                                $symbol_rev = '？';
                                break;
                        }
                        break;
                    case '┤':
                        switch ($dir_meaning) {
                            case 'L': // T字路の左折
                                $symbol_rev = '┬';
                                break;
                            case 'S': // T字路の直進
                                $symbol_rev = '├';
                                break;
                            default:
                                $symbol_rev = '？';
                                break;
                        }
                        break;
                    case '├':
                        switch ($dir_meaning) {
                            case 'R': // T字路の右折
                                $symbol_rev = '┬';
                                break;
                            case 'S': // T字路の直進
                                $symbol_rev = '┤';
                                break;
                            default:
                                $symbol_rev = '？';
                                break;
                        }
                        break;
                    case 'Ｙ':  // Y時の場合は記号の変換が難しい. 逆向きではポイント自体が不要になるかもしれない.
                        switch ($dir_meaning) {
                            case 'R': // Y字路の右折
                                $symbol_rev = '┬';
                                break;
                            case 'L': // Y字路の左折
                                $symbol_rev = '┬';
                                break;
                            default:
                                $symbol_rev = '？';
                                break;
                        }
                        break;
                }
                $name_rev = preg_replace("/$symbol/u", $symbol_rev, $name);
            } else {
                $name_rev = $name; // 交差点シンボルがないときはそのまま
            }

            // direction 変換 とりあえずは左右の変換のみ
            $direction_rev = preg_replace(array('/右/u', '/左/u'), array('LLL', 'RRR'), $direction);
            $direction_rev = preg_replace(array('/LLL/', '/RRR/'), array('左', '右'), $direction_rev);

            // route とりあえず経路の連結文字は'→'に限定
            $route = $c_prev ? $c_prev['route'] : '';
            $route_rev = implode('→', array_reverse(preg_split('/[ 　]*→[ 　]*/u', $route)));

            // pcNo
            $pcNo_rev = ''; // 読み込み後に振り直しされる
            // gpsIcon
            $gpsIcon_rev = array(// default icon
                'name' => 'N',
                'sym' => 'pin_blue',
                'symName' => 'Pin, Blue',
            );
            switch ($type_rev) {
                case 'start':
                    $gpsIcon_rev = array(
                        'name' => 'S',
                        'sym' => 'flag_green',
                        'symName' => 'Flag, Green',
                    );
                    break;
                case 'goal':
                    $gpsIcon_rev = array(
                        'name' => 'G',
                        'sym' => 'flag_red',
                        'symName' => 'Flag, Red',
                    );
                    break;
                case 'pc':
                    $gpsIcon_rev = array(
                        'name' => 'PC',
                        'sym' => 'information',
                        'symName' => 'Information',
                    );
                    break;
                case 'pass':
                    $gpsIcon_rev = array(
                        'name' => 'CHK',
                        'sym' => 'information',
                        'symName' => 'Information',
                    );
                    break;
                case 'point':
                    switch ($dir_meaning) {
                        case 'L':
                            $gpsIcon_rev = array(
                                'name' => 'R',
                                'sym' => 'pin_red',
                                'symName' => 'Pin, Red',
                            );
                            break;
                        case 'R':
                            $gpsIcon_rev = array(
                                'name' => 'L',
                                'sym' => 'pin_blue',
                                'symName' => 'Pin, Blue',
                            );
                            break;
                        case 'S':
                            $gpsIcon_rev = array(
                                'name' => 'S',
                                'sym' => 'pin_green',
                                'symName' => 'Pin, Green',
                            );
                            break;
                    }
            }
            // memo 備考は変換が難しいので触らない. 必ず直してもらうようにカッコで括っておく.
            $memo_rev = trim($c['memo']) != '' ? '（' . trim($c['memo']) . '）' : '';
            // openMin, closeMin 再計算される
            $openMin_rev = $closeMin_rev = false;
            // marker 
            $marker_rev = $c['marker'];
            // ptidx
            $ptidx_rev = $c['ptidx'];   // 逆さまから設定していくので idx はそのままで

            $cues_rev[$ptidx_rev] = array(
                'idx' => $idx_rev,
                'type' => $type_rev,
                'name' => $name_rev,
                'direction' => $direction_rev,
                'route' => $route_rev,
                'pcNo' => $pcNo_rev,
                'gpsIcon' => $gpsIcon_rev,
                'memo' => $memo_rev,
                'openMin' => $openMin_rev,
                'closeMin' => $closeMin_rev,
                'marker' => $marker_rev,
            );
        }

        $points_rev = array();
        for ($i = count($points) - 1; $i >= 0; $i--) {
            $point_rev = array(
                'show' => $points[$i]['show'],
                'cue' => isset($cues_rev[$i]) ? $cues_rev[$i] : false,
                'info' => false, // 'info'=>$points[$i]['info'], // ポイント情報は内容が古くなっていることもあるし, 容量を減らすためにも削除.
                'distance' => false
            );
            $points_rev[] = $point_rev;
        }

        // $exclude 除外区間. 開始と終了を入れ替えた配列.
        $exclude_rev = array();
        foreach ($exclude as $e) {
            $exclude_rev[] = array(
                'begin' => $cnt - $e['end'] - 1,
                'end' => $cnt - $e['begin'] - 1
            );
        }

        // $display
        $display_rev = $display;    // とりあえず変更なし. そのうち視点を変えるなど.
        
        // 再び配列にまとめる
        return self::assemble ( array(
            'info' => $info_rev,
            'points' => $points_rev,
            'cues' => $cues_rev,
            'exclude' => $exclude_rev,
            'display' => $display_rev,
        ), true);
    }

    /**
     * 分解したブルベデータを集めて json_encode する前のデータにまとめる
     * @param array $brm_data 分解した brmfile 情報
     * @param boolean $brandnew id を振り直すか. default で振り直す
     * @return array JSON になる前のBRMデータ
     */
    public static function assemble($brm_data, $brandnew = true) {

        $info = $brm_data['info'];
        $points = $brm_data['points'];
        $cues = $brm_data['cues'];
        $exclude = $brm_data['exclude'];
        $display = $brm_data['display'];

        return array(
            'id' => ( $brandnew || !isset($info['id']) || !$info['id']) ? floor(microtime(true)) : $info['id'], // '新しいID'
            'brmName' => $info['brmName'],
            'brmDistance' => $info['brmDistance'],
            'brmDate' => $info['brmDate'],
            'brmStartTime' => $info['brmStartTime'],
            'brmCurrentStartTime' => $info['brmCurrentStartTime'],
            'encodedPathAlt' => $info['encodedPathAlt'],
            'cueLength' => count($cues),
            'points' => $points,
            'exclude' => $exclude,
            'display' => $display
        );
    }

}
