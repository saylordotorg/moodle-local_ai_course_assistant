<?php
namespace local_ai_course_assistant;
defined('MOODLE_INTERNAL') || die();

class rag_judge_test extends \basic_testcase {
    public function test_ndcg_perfect_and_zero_and_mixed() {
        $this->assertEqualsWithDelta(1.0, rag_judge::ndcg_at_k([3, 2, 1], 3), 1e-9);
        $this->assertEqualsWithDelta(0.0, rag_judge::ndcg_at_k([0, 0, 0], 3), 1e-9);
        // grades [0,3,0]: DCG = 7/log2(3); IDCG = 7/log2(2)=7 -> 0.6309
        $this->assertEqualsWithDelta(0.63093, rag_judge::ndcg_at_k([0, 3, 0], 3), 1e-4);
    }
    public function test_precision_hit_mean() {
        $this->assertEqualsWithDelta(0.5, rag_judge::precision_at_k([3, 1, 2, 0], 4), 1e-9);
        $this->assertSame(0, rag_judge::hit_at_k([1, 0, 1], 3));
        $this->assertSame(1, rag_judge::hit_at_k([1, 2, 0], 3));
        $this->assertEqualsWithDelta(2.0, rag_judge::mean_relevance([3, 0, 3], 3), 1e-9);
    }
    public function test_parse_grades() {
        $this->assertSame([3, 2, 0, 1, 0], rag_judge::parse_grades('[3, 2, 0, 1, 0]', 5));
        // extra prose around the array is tolerated
        $this->assertSame([3, 2, 1, 0, 0], rag_judge::parse_grades("grades: [3,2,1,0,0] done", 5));
        // out-of-range clamped
        $this->assertSame([3, 0], rag_judge::parse_grades('[5, -1]', 2));
        // wrong length -> null
        $this->assertNull(rag_judge::parse_grades('[3, 2]', 5));
        // garbage -> null
        $this->assertNull(rag_judge::parse_grades('no json here', 3));
    }
}
