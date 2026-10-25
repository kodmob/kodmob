<?php

namespace App\Modules\Domains\Generators;

/**
 * Yerleşik kelime listeleri (jenerik tarama ve domain hilesi). ASCII, küçük
 * harf. Liste eklemek için buraya bir anahtar eklemek yeterli; panel ve Jarvis
 * listeleri `all()`'dan okur.
 */
final class Wordlists
{
    private const SETS = [
        'en-short' => [
            'title' => 'İngilizce · kısa jenerik',
            'words' => 'ace act add age aid aim air all amp ant app arc arm art ask atom aura auto axe bag ball band bank bar base bay beam bear beat bee bell belt best bet big bike bird bit black blade blend bliss blob bloom blue board boat bold bolt bond bone book boom boost boot boss box brain brand brave bread brick bridge bright brook buck bud bug build bulb bull bunny buzz byte cab cake call calm camp can cap car card care cart case cash cast cat cave cell chain chair chalk charm chart chat check chef chess chip cider city clap class claw clay clean clear click cliff climb clip clock cloud club coast coat code coin cold cole cool cope copy coral core corn cost cove cozy craft crane crew crisp crow crown cube cup curl cut cycle dart dash data dawn day deal deck deep deer desk dial dice dive dock dome door dot dove draft dream drift drive drop drum duck dune dust eagle earth ease echo edge egg elf elm ember epic eye face fair fame farm fast fawn feed fern field fig film find fire firm fish fit five flag flame flash fleet flex flip float flock flow fly foam focus fog folk font food fork form fort fox frame fresh frog front fruit fuel fun fund gain game gap gate gear gem giant gift glad glow glue goal goat gold golf good grab grain grape grass grid grow guide gust halo hand happy harbor hare hat haven hawk heart heat hero hill hint hive home honey hook hope horn host hub hug ice icon idea inch ink iron isle ivy jade jam jar jazz jet jewel job joy judge juice jump kale keen key kick kid kind king kit kite knot lab lace lake lamp land lane lark leaf lean leap lemon lens life lift light lily lime line link lion list loft logic loop lotus love luck lume lunar lush lynx magic mail main mango map maple mark mars mask mate maze meal mega melon mesh mild mile milk mill mind mine mint mix moon moss moth motion move muse nest net nest nine noble node nook north nova oak oasis ocean olive omni one onyx open orbit orca owl pace pack page pail palm pan panda park pass path peak pear pearl pen pep pet pick pie pier pig pilot pine pink pipe pixel plan plant play plum plus pod poem point polar pond pony pool pop port post pot pour press prime prism pro pulse pump pure quest quick quiet quill race rain ramp rank rapid raven ray reach real reef relay rest rice ride ring rise river road robin rock rocket roll roof room root rope rose round route ruby run rush safe sage sail salt sand save scale scout sea seal seed sense shape share shell shift shine ship shop shore sight sign silk silver sing site sky slate sleek slide smart smile snap snow soft solar sole song soul sound space spark spice spin spot spring spruce square star step stone storm story stream street stride sugar suite summit sun surf swan sweet swift table tail tale tank tap task tea team tech tent test thread tide tiger tile time tiny toast token tone tool top torch tour tower town toy track trade trail train tree trek tribe true trust tune turtle twin unit urban valley vault vibe view villa vine vista vivid voice volt vote wave way web well whale wheel wild wind wing wise wolf wood word work world yard yarn year yoga zen zero zest zinc zone zoom',
        ],
        'en-tech' => [
            'title' => 'İngilizce · teknoloji',
            'words' => 'ai api app apps algo analytics agent auth backup beta bit bits blob bot bots build cache chip cloud cluster code coder cog compute cortex crypto cyber dash data deploy dev devops digital disk dns docker edge engine fiber flow flux forge frame gpu grid hack hash host hub index infra io kernel key lab labs layer link linux logic loop matrix mesh meta micro mind ml mobile modem modular module nano neural net network neuro node nova ops orbit packet parse patch pixel pixels platform pod portal proto proxy pulse quant query quantum queue robo robot route router runtime saas scale script sdk sense server shell signal smart socket soft software spark stack stream sync synth system tech tensor terra token tool tools vector verse virtual vision volt web wire wired zone',
        ],
        'en-business' => [
            'title' => 'İngilizce · iş ve ticaret',
            'words' => 'ad ads agency asset assets audit bank bazaar bid bill brand brands broker budget buy buyer capital career cart cash catalog client coin commerce company consult contract coop corp credit crowd deal deals delivery desk direct earn equity estate exchange expert fair finance firm fleet fund funds gain goods grant group growth guild hire hiring income invest invoice job jobs launch lead leads ledger lend loan local market markets media member merchant mint money office order orders outlet owner partner pay payment payroll pitch plan pledge price prime profit promo quote rate realty rent retail revenue sale sales save seller service shop shops spend staff startup stock store studio supply tax team tender trade trader trust value vendor venture wallet wealth work works',
        ],
        'tr-genel' => [
            'title' => 'Türkçe · genel',
            'words' => 'ada adim ag akil akim alan alim anahtar ara arac arena arsiv asil ates atlas atolye av ay ayna bag bahar bahce bakim balik bank bant baris bas bayi bazar beyin bilgi bilim bina bir birlik bolum bors bulut burc cadde cami cark cati cay cep cicek cilt cizgi damla defter deger demir deniz dere dergi destek dev dijital dil dolap dost duvar duyuru dunya ekip ekol ekran el elma enerji ev evim fikir film firma fiyat gaz gece gemi genc gezi giris gol gonul gorev gida guc gul gun gunes hal hane hayat hedef hiz hizmet ilk is isik iz kafe kalem kale kalite kanal kapi kart kasa kat kavak kaynak kent kilit kitap kod koy kume kule kum kutu kurs lale liman masa mavi merkez mesaj meydan mimar moda mutfak nar not oda ofis okul oyun ozel pazar pencere plan puan radar rehber renk rota ruzgar saat sahne sanat sepet ses sinyal site sofra sokak sohbet su sunum tarla tasarim teklif tel temiz tohum toprak tren tur usta uzay vadi vatan yaprak yapi yazi yildiz yol yuva zeka zirve',
        ],
        'tr-is' => [
            'title' => 'Türkçe · iş ve hizmet',
            'words' => 'acente ajans araci avukat bakim bayi berber butik danis danisman depo doktor emlak eczane egitim fatura filo fiyat garanti gida hukuk ihale ihracat ilac imalat insaat ithalat kargo kasap kira klinik kooperatif kredi kurye lojistik magaza mali market matbaa mimarlik muhasebe musteri nakliye otel oto pastane perakende proje rezervasyon satis servis sigorta sirket siparis tamir tedarik teknik temizlik tesis ticaret toptan turizm uretim ustalar yatirim yayin yazilim yemek',
        ],
        'hack-si' => [
            'title' => 'Domain hilesi · .si ile biten kelimeler',
            'hack' => true,
            'words' => 'ustasi dunyasi kahvesi tatlisi bahcesi sofrasi masasi kasasi pastasi arabasi odasi kapisi firmasi markasi annesi babasi ablasi dedesi teyzesi halasi kafesi sinemasi pizzasi kolasi elmasi nanesi tarlasi adasi kiyisi bilgisi modasi dizisi gazetesi kisi basi tasi disi yasasi pazari harcamasi oasi casi cosi brasi tarsi parsi farsi',
        ],
    ];

    public const PREFIXES = ['get', 'go', 'my', 'try', 'use', 'hey', 'the', 'one', 'top', 'pro', 'all', 'we', 'be', 'neo', 'meta', 'smart', 'super'];

    public const SUFFIXES = ['app', 'hub', 'ly', 'lab', 'labs', 'hq', 'go', 'ify', 'now', 'pro', 'kit', 'box', 'base', 'ai', 'bot', 'way', 'desk', 'link', 'zone', 'stack'];

    /** @return list<array{key:string,title:string,count:int,hack:bool}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::SETS as $key => $set) {
            $out[] = ['key' => $key, 'title' => $set['title'], 'count' => count(self::words($key)), 'hack' => ! empty($set['hack'])];
        }

        return $out;
    }

    public static function exists(string $key): bool
    {
        return isset(self::SETS[$key]);
    }

    public static function isHack(string $key): bool
    {
        return ! empty(self::SETS[$key]['hack']);
    }

    /** @return list<string> */
    public static function words(string $key): array
    {
        return isset(self::SETS[$key]) ? array_values(array_unique(preg_split('/\s+/', trim(self::SETS[$key]['words'])))) : [];
    }

    public static function title(string $key): string
    {
        return self::SETS[$key]['title'] ?? $key;
    }
}
