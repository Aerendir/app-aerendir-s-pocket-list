<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Serializer\Encoder\CsvEncoder;

class DefaultController extends AbstractController
{
    //private const NGROK_URL = 'https://60ea27ac.ngrok.io';
    private const POCKET_CONSUMER_KEY = '87161-d3c6561787946806976c2ea7';
    private const BATCH_SIZE = 10;

    /**
     * @Route("/", name="index")
     */
    public function index(Request $request)
    {
        $session = $request->getSession();

        if (null === $session || null === $session->get('access_token') || null === $session->get('username')) {
            return $this->redirectToRoute('request');
        }

        $form = $this->createFormBuilder()
                     ->add('urls_list', FileType::class)
                     ->add('save', SubmitType::class, ['label' => 'Upload file list'])
                     ->getForm();

        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('upload.html.twig', [
                'form' => $form->createView(),
            ]);
        }

        /** @var UploadedFile $urlsListFile */
        $urlsListFile = $form['urls_list']->getData();

        // this condition is needed because the 'brochure' field is not required
        // so the PDF file must be processed only when a file is uploaded
        if (!$urlsListFile) {
            throw new \RuntimeException('Something gone wrong uploading the file');
        }

        $csvList = file_get_contents($urlsListFile->getPathname());
        $decoder = new CsvEncoder();
        $list = $decoder->decode($csvList, CsvEncoder::FORMAT);

        $batches = [];

        $currentBatch = 0;
        $currentInBatch = 0;

        foreach ($list as $url) {
            if ($currentInBatch / self::BATCH_SIZE === 1) {
                $currentBatch++;
                $currentInBatch = 0;
            }

            if (is_array($url)) {
                foreach ($url as $key => $value) {
                    // It seems that simply using $url['URL'] doesn't work -.-'
                    $batches[$currentBatch][] = [
                        'action' => 'add',
                        'url' => $url[$key],
                        'tags' => 'zest',
                        'time' => time()
                    ];
                }
            }

            $currentInBatch++;
        }

        dump($batches);

        foreach ($batches as $position => $batch) {
            echo '<h1>Batch #' . $position . '</h1>';
            $actions  = json_encode($batch);
            $client   = HttpClient::create();
            $response = $client->request('GET', 'https://getpocket.com/v3/send', [
                'query' => [
                    'consumer_key' => self::POCKET_CONSUMER_KEY,
                    'access_token' => $session->get('access_token'),
                    'actions'      => $actions
                ]
            ]);

            dump($response->getStatusCode());
            dump($response->getHeaders());
            dump($response->getContent());
            dump($response->getInfo());
        }

        dd('fine');
    }

    /**
     * @Route("/request", name="request")
     */
    public function request(Request $request)
    {
        $form = $this->createFormBuilder()
                     ->add('return_url', TextType::class)
                     ->add('save', SubmitType::class, ['label' => 'Connect to Pocket'])
                     ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ngrokUrl = $form->getData()['return_url'];

            $redirectUri = $ngrokUrl . '/authorized';

            // Step 1: Get the request token
            $httpClient = HttpClient::create();
            $response   = $httpClient->request('POST', 'https://getpocket.com/v3/oauth/request', [
                'json'    => [
                    'consumer_key' => self::POCKET_CONSUMER_KEY,
                    'redirect_uri' => $ngrokUrl
                ],
                'headers' => [
                    'X-Accept' => 'application/json',
                ],
            ])->toArray();

            $session = $request->getSession();

            if (null === $session) {
                throw new \RuntimeException('Session not available');
            }

            $session->set('request_token', $response['code']);

            $params = [
                'request_token' => $response['code'],
                'redirect_uri'  => $redirectUri
            ];

            //$builtQuery = implode('&', $params);
            $builtQuery = http_build_query($params, '', '&');

            return new RedirectResponse('https://getpocket.com/auth/authorize?' . $builtQuery);
        }

        return $this->render('request.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/authorized", name="authorized")
     */
    public function authorized(Request $request)
    {
        $session = $request->getSession();

        if (null === $session) {
            throw new \RuntimeException('Session not available');
        }

        // Step 1: Get the request token
        $httpClient = HttpClient::create();
        $response = $httpClient->request('POST', 'https://getpocket.com/v3/oauth/authorize', [
            'json' => [
                'consumer_key' => self::POCKET_CONSUMER_KEY,
                'code' => $session->get('request_token')
            ],
            'headers' => [
                'X-Accept' => 'application/json',
            ],
        ])->toArray();

        $session->set('access_token', $response['access_token']);
        $session->set('username', $response['username']);

        return $this->redirectToRoute('index');
    }
}
