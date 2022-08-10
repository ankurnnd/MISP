<?php
App::uses('AppModel', 'Model');
App::uses('RandomTool', 'Tools');

class OverCorrelatingValue extends AppModel
{
    public $recursive = -1;

    public $actsAs = array(
        'Containable'
    );

    public $validate = [
    ];

    public function block($value, $count = 0)
    {
        $this->unblock($value);
        $this->create();
        $this->save(
            [
                'value' => $value,
                'occurrence' => $count
            ]
        );
    }

    public function unBlock($value)
    {
        $this->deleteAll(
            [
                'OverCorrelatingValue.value' => $value
            ]
        );
    }

    public function getLimit()
    {
        return Configure::check('MISP.correlation_limit') ? Configure::read('MISP.correlation_limit') : 20;
    }

    public function getOverCorrelations($query)
    {
        $data = $this->find('all', $query);
        $limit = $this->getLimit();
        foreach ($data as $k => $v) {
            if ($v['OverCorrelatingValue']['occurrence'] >= $limit) {
                $data[$k]['OverCorrelatingValue']['over_correlation'] = true;
            } else {
                $data[$k]['OverCorrelatingValue']['over_correlation'] = false;
            }
        }
        return $data;
    }

    public function checkValue($value)
    {
        $hit = $this->find('first', [
            'recursive' => -1,
            'conditions' => ['value' => $value],
            'fields' => ['id']
        ]);
        if (empty($hit)) {
            return false;
        }
        return true;
    }

    public function generateOccurrencesRouter()
    {
        if (Configure::read('MISP.background_jobs')) {
            /** @var Job $job */
            $job = ClassRegistry::init('Job');
            $jobId = $job->createJob(
                'SYSTEM',
                Job::WORKER_DEFAULT,
                'generateOccurrences',
                '',
                'Starting populating the occurrences field for the over correlating values.'
            );

            $this->getBackgroundJobsTool()->enqueue(
                BackgroundJobsTool::DEFAULT_QUEUE,
                BackgroundJobsTool::CMD_ADMIN,
                [
                    'jobGenerateOccurrences',
                    $jobId
                ],
                true,
                $jobId
            );

            return $jobId;
        } else {
            return $this->generateOccurrences();
        }
    }

    public function generateOccurrences()
    {
        $overCorrelations = $this->find('all', [
            'recursive' => -1
        ]);
        $this->Attribute = ClassRegistry::init('Attribute');
        foreach ($overCorrelations as &$overCorrelation) {
            $count = $this->Attribute->find('count', [
                'recursive' => -1,
                'conditions' => [
                    'OR' => [
                        'Attribute.value1' => $overCorrelation['OverCorrelatingValue']['value'],
                        'Attribute.value2' => $overCorrelation['OverCorrelatingValue']['value']
                    ]
                ]
            ]);
            $overCorrelation['OverCorrelatingValue']['occurrence'] = $count;
        }
        $this->saveMany($overCorrelations);
    }
}
