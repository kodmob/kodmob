<?php

namespace App\Core\Search;

/**
 * Panel aramasında kendi kayıtlarını arayan içerik türü. ContentType ve BlogPosts uygular;
 * `kodmob:module` ile üretilen tür ContentType'tan geldiği için aranır hale kendiliğinden gelir.
 */
interface Searchable
{
    /**
     * Başlıkta geçen kayıtlar (silinenler hariç), yeniden eskiye.
     *
     * @return list<array{id:int,title:string,subtitle:string,badge:string}>
     */
    public function searchRecords(string $term, int $limit): array;
}
