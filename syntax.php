<?php
/**
 * DokuWiki Plugin translationbuddy (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Håkan Sandell <sandell.hakan@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once DOKU_PLUGIN.'syntax.php';

class syntax_plugin_translationbuddy extends DokuWiki_Syntax_Plugin {
    public function getType() {
        return 'substition';
    }

    public function getPType() {
        return 'block';
    }

    public function getSort() {
        return 99;
    }

    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *translationbuddy *-+\n.*?\n----+',$mode,'plugin_translationbuddy');
        }

    public function handle($match, $state, $pos, &$handler){
        return $this->parseData($match);
    }

    public function render($mode, &$renderer, $data) {
        if($mode == 'xhtml') {
            return $this->_showData($renderer,$data);
        }
        return false;
    }

    /**
     * Parse syntax data block, return keyed array of values
     *
     *  You may use the # character to add comments to the block.
     *  Those will be ignored and will neither be displayed nor saved.
     *  If you need to enter # as data, escape it with a backslash (\#).
     *  If you need a backslash, escape it as well (\\)
     */
    function parseData($match){
        // get lines
        $lines = explode("\n",$match);
        array_pop($lines);
        array_shift($lines);

        // parse info
        $data = array();
        foreach ( $lines as $line ) {
            // ignore comments and bullet syntax
            $line = preg_replace('/(?<![&\\\\])#.*$/','',$line);
            $line = preg_replace('/^  \* /','',$line);
            $line = str_replace('\\#','#',$line);
            $line = trim($line);
            if(empty($line)) continue;
            list($key,$value) = preg_split('/\s*:\s*/',$line,2);
            $key = strtolower($key);
            if ($data[$key]){
                $data[$key] .= ' ' . trim($value);
            }else{
                $data[$key] = trim($value);
            }
        }
        return $data;
    }

    function _showData(&$R, $data){
        global $ID, $conf;

        // default settings
        if (!is_numeric($data['daysnew'])) $data['daysnew'] = 14;
        if (!is_numeric($data['outdated'])) $data['outdated'] = 5;
        if ($data['ignore_ns']) {
            $data['ignore_ns'] = str_replace(',', ' ', $data['ignore_ns']);
            $ignore_ns = explode(' ', $data['ignore_ns']);
            $ignore_ns = array_filter($ignore_ns);
        } else {
            $ignore_ns = array();
        }
        if ($data['langs']) {
            $data['langs'] = str_replace(',', ' ', $data['langs']);
            $langs = explode(' ', $data['langs']);
            $langs = array_filter($langs);
        } elseif ($conf['plugin'] && $conf['plugin']['translation']) {
            $langs = explode(' ', $conf['plugin']['translation']['translations']);
            $langs = array_filter($langs);
        } else {
            $langs = array();
        }

        // get all pages
        $pages = idx_get_indexer()->getPages();
        $items = array();
        $new_pages = array();
        foreach($pages as $id){
            //skip hidden, non existing and restricted files
            if(isHiddenPage($id)) continue;
            if(auth_aclcheck($id,'','') < AUTH_READ) continue;

            $page = $id;
            $ns = $this->baseNS($page);
            $lc = 'en';
            if ($ns && in_array($ns,$langs)) {
                $lc = $ns;
                $page = $this->skipBaseNS($page);
                $ns = $this->baseNS($page);
            }
            if (!$ns) $ns = '-';

            $fn = wikiFN($id);
            $date = @filemtime($fn);
            if($date && !in_array($ns, $ignore_ns)) {

                $items[$lc][$ns][$page] = $date;
                if (filectime($fn) > time() - 60*60*24*$data['daysnew']) {
                    $new_pages[] = $id;
                }
            }
        }
        sort($new_pages);

        // new page report
        $R->doc .= '<b>New pages last '.hsc($data['daysnew']).' days</b>';
        $R->listu_open();
        foreach ($new_pages as $page) {
            $R->listitem_open(1);
            $R->listcontent_open();
            $R->doc .= '<a href="'.wl($page).'">'.$page.'</a>';
            $R->listcontent_close();
            $R->listitem_close();
        }
        $R->listu_close();
        $R->doc .= '<b>Translation status</b>';

        // translation effort report
        $R->doc .= '<table>';
        $R->doc .= '<tr><th>Lang</th><th>Namespace</th><th>Pages</th><th>Translated</th><th>Details</th></tr>';
        foreach ($items as $lc => $item) {
            $pageTotal = 0;
            $first = true;
            foreach ($item as $ns => $pages) {
                $R->doc .= '<tr>';
                if ($first) {
                    $first = false;
                    $R->doc .= '<td rowspan="'.count($item).'" id="translationbuddy__'.$lc.'">'.$lc.'</td>';
                    $R->toc_additem('translationbuddy__'.$lc, $lc, $R->lastlevel+1);
                }
                $idx = ($ns=='-' ? ($lc=='en'?'-1':$lc) : ($lc=='en'?'':$lc.':').$ns);
                $R->doc .= '<td><a href="'.wl($ID,'idx='.rawurlencode($idx)).'" >'.$ns.'</a></td>';
                $R->doc .= '<td class="rightalign">'.count($pages).'</td>';
                if (count($items['en'][$ns]) == 0) {
                    $R->doc .= '<td class="rightalign"> - </td>';
                } else {
                    $R->doc .= '<td class="rightalign">'.round(100*count($pages)/count($items['en'][$ns])).'%</td>';
                }

                $R->doc .= '<td>';
                // outdated
                $outdated = array();
                foreach ($pages as $id => $date) {
                    if ($date < $items['en'][$ns][$id]) {
                        $outdated[] = $id;
                    }
                }
                if (count($outdated) > 0) {
                    $itr = 0;
                    $R->doc .= '<b>Outdated:</b><br/>';
                    do
                        $R->doc .= '<a href="'.wl($lc.':'.$outdated[$itr]).'">'.$outdated[$itr].'</a> ';
                    while (++$itr < $data['outdated'] && $itr < count($outdated));
                    if ($itr < count($outdated)) {
                        $R->doc .= 'and '.(count($outdated)-$itr).' more.';
                    }
                    $R->doc .= '<br/> ';
                }
                // rouge pages
                if (count($items['en'][$ns]) > 0) {
                    $extra_pages = array_keys(array_diff_key($pages,$items['en'][$ns]));
                    $func = create_function('$id' , "return '<a href=\"'.wl('$lc:'.\$id).'\">'.\$id.'</a>';");
                    if (count($extra_pages) > 0) {
                        $extra_pages = array_map($func, $extra_pages);
                        $R->doc .= '<b>Rogue pages:</b><br/> '.implode(', ', $extra_pages).'<br/>';
                    }
                }

                $R->doc .= '</td>';
                $R->doc .= '</tr>';
                $pageTotal += count($pages);
            }
            $R->doc .= '<tr>';
            $R->doc .= '<th>'.$lc.'</th>';
            $R->doc .= '<th>Total</th>';
            $R->doc .= '<th class="rightalign">'.$pageTotal.'</th>';
            $R->doc .= '<th></th>';
            $R->doc .= '<th></th>';
            $R->doc .= '</tr>';
        }
        $R->doc .= '</table>';
    }

    function baseNS($id){
        $pos = strpos((string)$id,':');
        if($pos!==false){
            return substr((string)$id,0,$pos);
        }
        return false;
    }

    function skipBaseNS($id){
        $pos = strpos((string)$id,':');
        if($pos!==false){
            return substr((string)$id,$pos+1);
        }
        return false;
    }
}

