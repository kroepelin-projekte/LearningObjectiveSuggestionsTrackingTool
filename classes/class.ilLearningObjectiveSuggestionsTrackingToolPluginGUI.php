<?php

use ILIAS\UI\Component\Input\Container\Form\Standard;
use SRAG\ILIAS\Plugins\LearningObjectiveSuggestions\Config\CourseConfig;
use Mpdf\MpdfException;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;
use setasign\Fpdi\PdfParser\Type\PdfTypeException;
use ILIAS\DI\Container;
use ILIAS\UI\Factory;
use ILIAS\UI\Renderer;
use ILIAS\Container\InternalDomainService;
use JetBrains\PhpStorm\NoReturn;

/**
 * @ilCtrl_isCalledBy ilLearningObjectiveSuggestionsTrackingToolPluginGUI: ilPCPluggedGUI
 * @ilCtrl_isCalledBy ilLearningObjectiveSuggestionsTrackingToolPluginGUI: ilUIPluginRouterGUI
 * /
 */
class ilLearningObjectiveSuggestionsTrackingToolPluginGUI extends ilPageComponentPluginGUI
{
    const CMD_PRINT_CERTIFICATE = 'printCertificate';

    const CMD_CREATE = 'create';

    const CMD_EDIT = 'edit';

    const CMD_UPDATE = 'update';

    const CMD_CANCEL = 'cancel';

    private $tpl;

    private Container $dic;

    private ilCtrlInterface $ctrl;

    private ilPlugin $pl;

    private Factory $factory;

    private Renderer $renderer;

    private int $userId;

    public function __construct()
    {
        global $DIC;

        parent::__construct();
        $this->dic = $DIC;
        $this->ctrl = $this->dic->ctrl();
        $this->pl = ilLearningObjectiveSuggestionsTrackingToolPlugin::getInstance();
        $this->lng = $this->dic->language();
        $this->factory = $this->dic->ui()->factory();
        $this->renderer = $this->dic->ui()->renderer();
        $this->userId = $DIC->user()->getId();
    }

    /**
     * Commands
     *
     * @return void
     * @throws ilCtrlException
     */
    public function executeCommand(): void
    {
        $cmd = $this->ctrl->getCmd();

        $commands = [
            self::CMD_CREATE,
            self::CMD_UPDATE,
            self::CMD_EDIT,
            self::CMD_CANCEL,
            self::CMD_PRINT_CERTIFICATE
        ];
        if (in_array($cmd, $commands)) {
            $this->$cmd();
        }
    }

    /**
     * @return void
     * @throws ilCtrlException
     */
    public function insert(): void
    {
        global $DIC;

        $DIC->ctrl()->redirectByClass(self::class, 'create');
    }

    /**
     * Override Method of ilPageComponentPluginGUI()
     * Creating the editing dialog (opening config after the first time)
     *
     * @return void
     * @throws ilCtrlException
     */
    public function edit(): void
    {
        global $DIC;

        $form = $this->buildConfigForm();
        $DIC->ui()->mainTemplate()->setContent($this->renderer->render($form));
    }

    /**
     * @throws ilCtrlException
     */
    public function buildConfigForm(): Standard
    {
        global $DIC;

        $properties = $this->getProperties();

        $field = $this->factory->input()->field();
        $inputRefId = $field->text($this->plugin->txt('ref_id'))
                              ->withValue($properties['ref_id'] ?? '');

        $inputEntryTest = $field->checkbox($this->plugin->txt('entry_test_display'))
                            ->withValue(!empty($properties['entry_test']));

        $form = $this->factory->input()->container()->form()->standard(
            $DIC->ctrl()->getFormAction($this, 'update'),
            [
                'ref_id' => $inputRefId,
                'entry_test' => $inputEntryTest
            ]
        );

        return $form;
    }

    /**
     * @return void
     */
    private function saveConfig(): void
    {

    }

    /**
     * @return void
     */
    public function create(): void
    {
        global $DIC;

        $properties = [];
        if ($this->createElement($properties)) {
            $tpl = $DIC->ui()->mainTemplate();
            $tpl->setOnScreenMessage('success', 'Tracking Tool wurde angelegt', true);
            $this->returnToParent();
        }

    }

    /**
     * Override Method of ilPageComponentPluginGUI()
     *
     * Update config (save Form)
     *
     * @return void
     * @throws ilCtrlException
     */
    public function update(): void
    {
        global $DIC;

        $tpl = $DIC->ui()->mainTemplate();
        $form = $this->buildConfigForm();
        $formRequest = $form->withRequest($DIC->http()->request());
        $formData = $formRequest->getData();

        $refId = (int) $formData['ref_id'];
        $entryTest = $formData['entry_test'];

        if ($form->getError()) {
            $this->tpl->setOnScreenMessage('failure', $this->plugin->txt('error'));
            $this->returnToParent();
        }

        if ($refId === 0) {
            $tpl->setOnScreenMessage('failure', $this->plugin->txt('updated_failure'), true);
            $this->returnToParent();
        }
        $properties = $this->getProperties();
        $properties['ref_id'] = $refId;
        $properties['entry_test'] = $entryTest;

        if ($this->updateElement($properties)) {
            $tpl->setOnScreenMessage('success', $this->plugin->txt('updated_success'), true);
            $this->returnToParent();
        }
    }

    /**
     * Cancel button - cancel editing
     */
    public function cancel(): void
    {
        $this->returnToParent();
    }

    /**
     * HTML Content
     *
     * @param       $a_mode
     * @param array $a_properties
     * @param       $plugin_version
     * @return string
     * @throws ilTemplateException
     * @throws ilCtrlException
     * @throws ilSystemStyleException
     */
    public function getElementHTML($a_mode, array $a_properties, $plugin_version): string
    {
        global $DIC;

        if ($DIC->user()->isAnonymous()) {
            return ' ';
        }

        if ($a_mode === 'presentation' && !empty($a_properties)) {
            $participants = ilCourseParticipants::getInstance($a_properties['ref_id']);
            if (!$participants->isAssigned($DIC->user()->getId())) {
                return ' ';
            }
        }

        $this->pluginTemplate();

        if (!empty($a_properties)) {
            $learningObjectives = TrackingTool::getTrackingToolLearningObjectives(
                $DIC->user()->getId(),
                $a_properties['ref_id'] ?? null
            );

            $notRecommendedLearningObjectives = [];
            foreach ($learningObjectives as $key => $learningObjective) {
                if (!$learningObjective['suggested']) {
                    $notRecommendedLearningObjectives[$key] = $learningObjective;
                    unset($learningObjectives[$key]);
                }
            }

            $this->buildAccordionHtml(
                $learningObjectives,
                $notRecommendedLearningObjectives,
                $a_properties['ref_id'] ?? null,
                isset($a_properties['entry_test']) && (bool) $a_properties['entry_test']
            );
        }
        return $this->tpl->get();
    }

    /**
     * @throws ilCtrlException
     */
    private function getModal()
    {
        $modalFormAction = $this->dic->ctrl()->getLinkTargetByClass(
            [ilUIPluginRouterGUI::class, ilLearningObjectiveSuggestionsTrackingToolPluginGUI::class],
            self::CMD_PRINT_CERTIFICATE
        );

        $participationCertificatePlugin = ilParticipationCertificatePlugin::getInstance();

        $userId = $this->dic->user()->getId();

        $userData = ilPartCertUsersData::getData($participationCertificatePlugin, [$userId]);
        $firstname = $userData[$userId]->getPartCertFirstname();
        $lastname = $userData[$userId]->getPartCertLastname();

        $firstnameField = $this->factory->input()->field()->text($this->pl->txt('firstname'))
                                        ->withDedicatedName('firstname')
                                        ->withValue($firstname ?? '')
                                        ->withDisabled(!empty($firstname));

        $lastnameField = $this->factory->input()->field()->text($this->pl->txt('lastname'))
                                       ->withDedicatedName('lastname')
                                       ->withValue($lastname ?? '')
                                       ->withDisabled(!empty($lastname));

        $hiddenFirstnameField = $this->factory->input()->field()->hidden()
                                       ->withDedicatedName('hidden_firstname')
                                       ->withValue($firstname ?? '');

        $hiddenLastnameField = $this->factory->input()->field()->hidden()
                                              ->withDedicatedName('hidden_lastname')
                                              ->withValue($lastname ?? '');

        $sectionUserData = $this->factory->input()->field()->section(
            [
                'firstname' => $firstnameField,
                'lastname' => $lastnameField,
                'hidden_firstname' => $hiddenFirstnameField,
                'hidden_lastname' => $hiddenLastnameField
            ],
            '',
            ''
        )->withDedicatedName('user_data');

        $userFields['user_data'] = $sectionUserData;

        $sectionInfo = $this->factory->input()->field()->section(
            [],
            '',
            $this->pl->txt('modal_box_info')
        );

        $info['info'] = $sectionInfo;

        $courses = $this->getUserCourses($userId);

        $optionsFields = [];
        $coursesWithActivatedEMentoring = [];
        foreach ($courses as $course) {
            $courseRefId = $this->getCourseRefId($course['obj_id']);
            $certificateAccess = new ilParticipationCertificateAccess($courseRefId);

            if ($certificateAccess->hasCurrentUserPrintAccess(true)) {
                $checkboxes = [];

                $checkboxes['course_suggested_courses_' . $course['obj_id']] = $this->factory->input()->field()->checkbox(
                    $this->pl->txt('suggested_courses')
                )->withDedicatedName('course_suggested_courses_' . $course['obj_id']);

                $checkboxes['course_not_suggested_courses_' . $course['obj_id'] ] = $this->factory->input()->field()->checkbox(
                    $this->pl->txt('not_suggested_courses')
                )->withDedicatedName('course_not_suggested_courses_'  . $course['obj_id']);

                $checkboxes['course_final_test_' . $course['obj_id']] = $this->factory->input()->field()->checkbox(
                    $this->pl->txt('final_test')
                )->withDedicatedName('course_final_test_' . $course['obj_id']);

                $userCourseRefIds = ilObject::_getAllReferences($course['obj_id']);
                $userCourseRefId = array_shift($userCourseRefIds);

                $eMentoring = (bool) ilParticipationCertificateConfig::getConfig('enable_ementoring', $userCourseRefId);
                if($eMentoring) {
                    $coursesWithActivatedEMentoring[] = $course['obj_id'];
                }

                $sectionCheckboxes = $this->factory->input()->field()->section(
                    $checkboxes,
                    ilObjCourse::_lookupTitle($course['obj_id']),
                    ''
                )->withDedicatedName('options_'  . $course['obj_id']);

                $optionsFields['course_' . $course['obj_id']] = $sectionCheckboxes;
            }
        }

        if (!empty($coursesWithActivatedEMentoring)) {
            $optionsFields['ementoring'] = $this->factory->input()->field()->checkbox(
                $this->pl->txt('ementoring')
            )->withDedicatedName('ementoring');

            $optionsFields['homework'] = $this->factory->input()->field()->checkbox(
                $this->pl->txt('homework')
            )->withDedicatedName('homework');

            $optionsFields['individual_assesments'] = $this->factory->input()->field()->checkbox(
                $this->pl->txt('individual_assesments')
            )->withDedicatedName('individual_assesments');

            $optionsFields['sessions'] = $this->factory->input()->field()->checkbox(
                $this->pl->txt('sessions')
            )->withDedicatedName('sessions');
        }
        $fields = array_merge($userFields, $info, $optionsFields);

        $modal = $this->factory->modal()->roundtrip(
            $this->pl->txt('print_certificate'),
            [],
            $fields,
            $modalFormAction
        )->withDedicatedName('tracking-tool-modal')
         ->withSubmitLabel($this->pl->txt('modal_box_submit_button'))
         ->withOnLoadCode(function ($id) {
                return <<<JS
          
                const modal = $('#$id');
                const form = $('#$id .modal-footer form');
                const formBody = $('#$id .modal-body form');
                const formHeader = $('#$id .modal-header form');
                const closeButton = formHeader.find('button').first();
                const submitButton = form.find('button').first();

                const firstname = $('#$id input[name="form/user_data/firstname"]');
                const lastname = $('#$id input[name="form/user_data/lastname"]');
                const eMentoring = $('#$id fieldset[data-il-ui-input-name="form/ementoring"] input[name="form/ementoring"]');
                const homework = $('#$id fieldset[data-il-ui-input-name="form/homework"]');
                const individualAssesments = $('#$id fieldset[data-il-ui-input-name="form/individual_assesments"]');
                const sessions = $('#$id fieldset[data-il-ui-input-name="form/sessions"]');
                
                submitButton.attr('disabled', true);
                
                function toggleButton() {
                    if (firstname.val().length === 0 || lastname.val().length === 0) {
                        submitButton.prop('disabled', true);
                    } else {
                        submitButton.prop('disabled', false);
                    }
                }
                
                function redirection() {
                    const redirectUrl = new URL(window.location.href);
                    redirectUrl.searchParams.delete('tracking_tool_ref_id');
                    window.location.href = redirectUrl.toString();
                }
                
                toggleButton();
                
                firstname.change(function() {
                 toggleButton();
                });
                
                 lastname.change(function() {
                   toggleButton()
                });
                
                eMentoring.change(function() {
                  if($(this).is(':checked')) {
                    homework.css('display', 'grid');
                    individualAssesments.css('display', 'grid');
                    sessions.css('display', 'grid');
                  } else {
                    homework.css('display', 'none');
                    individualAssesments.css('display', 'none');
                    sessions.css('display', 'none');
                  }
                }); 
                
                closeButton.click(function() {
                   redirection();
                });
                
                modal.on('submit', '.modal-body form', function(e) {
                  e.preventDefault();
                  
                  const formData = new FormData(this);
                  
                  $.ajax({
                    url: formBody.attr('action'),
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function (data) {
                        if (data.success && data.pdf_base64) {
                            // Convert Base64 string to binary data
                            const byteChars = atob(data.pdf_base64);
                            const byteNumbers = new Array(byteChars.length);
                            for (let i = 0; i < byteChars.length; i++) {
                                byteNumbers[i] = byteChars.charCodeAt(i);
                            }
                            const byteArray = new Uint8Array(byteNumbers);
                    
                            // Create a Blob (PDF)
                            const blob = new Blob([byteArray], { type: 'application/pdf' });
                    
                            // Create a download URL
                            const url = URL.createObjectURL(blob);
                    
                            // Trigger download
                            const link = document.createElement('a');
                            link.href = url;
                            link.download = 'certificate.pdf';
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                    
                            // Free memory
                            URL.revokeObjectURL(url);
                    
                            // 5) Redirect after download
                            redirection();
                        } else {
                            redirection();
                        }
                    },
                    error: function (xhr, status, error) {
                        redirection()
                    }
                });
                
           });
        JS;
            });
        ;

        return $modal->withOnLoad($modal->getShowSignal());
    }

    /**
     * @return void
     * @throws ilSystemStyleException
     * @throws ilTemplateException
     */
    private function pluginTemplate(): void
    {
        $template = $this->dic->ui()->mainTemplate();
        $template->addCss(LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY . '/templates/css/tracking-tool.css');
        $template->addJavaScript(LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY . '/templates/js/tracking-tool.js');

        $this->tpl = new ilTemplate(
            'tpl.tracking-tool.html',
            true,
            true,
            'public/' . LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY,
            ilGlobalTemplateInterface::DEFAULT_BLOCK,
            true
        );
    }

    /**
     * @param array       $learningObjectives
     * @param array       $learningObjectivesNotRecommended
     * @param string|null $propertiesRefId
     * @param bool        $propertiesEntryTest
     * @return void
     * @throws ilCtrlException
     * @throws Exception
     */
    private function buildAccordionHtml(
        array $learningObjectives,
        array $learningObjectivesNotRecommended,
        ?string $propertiesRefId = null,
        bool $propertiesEntryTest = false
    ): void {
        global $DIC;

        $index = 1;

        $suggestedKeys = array_keys(array_filter($learningObjectives, function ($target) {
            return !empty($target['suggested']) && $target['suggested'] === true;
        }));
        $totalSuggestions = count($suggestedKeys);

        $navigationHistory = $DIC['ilNavigationHistory']->getItems();

        $lastVisitedObjId = null;
        foreach ($navigationHistory as $historyItem) {
            $historyItemObjectId = ilObject::_lookupObjectId($historyItem['ref_id']);

            foreach ($learningObjectives as $key => $learningObjective) {
                if ($historyItem['type'] === 'crs' &&
                    $historyItemObjectId == $key
                ) {
                    $lastVisitedObjId = $historyItemObjectId;
                    break;
                }
            }

            if (!empty($lastVisitedObjId)) {
                break;
            }
        }

        $htmlSuggestedCourses = $this->getAccordionHtml(
            $learningObjectives,
            $index,
            $lastVisitedObjId,
            false,
            $suggestedKeys,
            $totalSuggestions
        );

        $courseRefId = $this->fetchUrlParameter('tracking_tool_ref_id', FILTER_SANITIZE_NUMBER_INT);

        $htmlNotSuggestedCourses = $this->getAccordionHtml(
            $learningObjectivesNotRecommended,
            $index,
            $lastVisitedObjId,
            true
        );

        $type = ilObject::_lookupType($propertiesRefId, true);

        if (!empty($propertiesRefId) && $type === 'crs') {
            $courseObjId = ilObjCourse::_lookupObjectId($propertiesRefId);
            if ($propertiesEntryTest) {
                $htmlNotSuggestedCourses .= '<div class="container-initial-test-button">' . $this->buildEntryTestButton($courseObjId) . '</div>';
            }
        }

        if (!empty($courseRefId)) {
            $this->ctrl->setParameterByClass(
                self::class,
                'tracking_tool_ref_id',
                $courseRefId
            );

            $htmlNotSuggestedCourses .= $this->renderer->render(
                component: [$this->getModal()]
            );
        }

        $this->setTemplateBlock($htmlSuggestedCourses, $htmlNotSuggestedCourses, $propertiesRefId);
    }

    /**
     * @throws ilCtrlException
     */
    private function getAccordionHtml(
        array $learningObjectives,
        int &$index,
        $lastVisitedObjId,
        bool $notRecommendedCourses = false,
        ?array $suggestedKeys = null,
        ?int $totalSuggestions = null
    ): string {
        $html = '';

        foreach ($learningObjectives as $learningObjectiveObjId => $learningObjective) {
            $classStatusCourses = 'completed';
            if ($learningObjective['count_completed_courses'] < count($learningObjective['courses'])) {
                $classStatusCourses = 'not-completed';
                $checkIcon = 'not-completed.svg';
            } else {
                $checkIcon = 'passed.svg';
            }

            $courseRefId = $this->getCourseRefId($learningObjectiveObjId);
            $this->setRefIdAsClassParameter($courseRefId);
            $courseLink = $this->getCourseLink();

            $suggestedCoursesExist = false;
            $countWeightSymbols = 0;
            if ($suggestedKeys !== null) {
                $suggestedCoursesExist = true;

                if (($suggestedIndex = array_search($learningObjectiveObjId, $suggestedKeys)) !== false) {
                    if ($suggestedIndex === 0) {
                        $countWeightSymbols = 3;
                    } elseif ($suggestedIndex === $totalSuggestions - 1) {
                        $countWeightSymbols = 1;
                    } else {
                        $countWeightSymbols = 2;
                    }
                }
            }

            $htmlIconsAlert = '';
            for ($i = 1; $i <= $countWeightSymbols; $i++) {

                if ($learningObjective['count_completed_courses'] === count($learningObjective['courses'])) {
                    $htmlIconsAlert .= '<img src="' . LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY . '/templates/images/alert-completed.svg" class="icon-weight">';
                } else {
                    $htmlIconsAlert .= '<img src="' . LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY . '/templates/images/alert.svg" class="icon-weight">';
                }
            }

            if ($suggestedCoursesExist) {
                if ($countWeightSymbols === 1) {
                    $htmlIconsAlert .= '<img src="' . LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY . '/templates/images/alert-secondary.svg" class="icon-weight">';
                    $htmlIconsAlert .= '<img src="' . LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY . '/templates/images/alert-secondary.svg" class="icon-weight">';
                } elseif ($countWeightSymbols === 2) {
                    $htmlIconsAlert .= '<img src="' . LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY . '/templates/images/alert-secondary.svg" class="icon-weight">';
                }
            } else {
                $htmlIconsAlert .= '<img src="' . LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY . '/templates/images/alert-secondary.svg" class="icon-weight">';
                $htmlIconsAlert .= '<img src="' . LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY . '/templates/images/alert-secondary.svg" class="icon-weight">';
                $htmlIconsAlert .= '<img src="' . LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY . '/templates/images/alert-secondary.svg" class="icon-weight">';
            }

            $html .= '<div class="tracking-tool-accordion-item">';
            if ($learningObjective['suggested']) {
                $html .= '<img src="' . LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY . '/templates/images/tree_col.svg" class="tracking-tool-tree-icon tracking-tool-active" data-action="collapse">';
            } else {
                $html .= '<img src="' . LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY . '/templates/images/tree_col.svg" class="tracking-tool-tree-icon" data-action="expand">';
            }
            $html .= '<span class="learning-objective-title">';
            $html .= '<a href="' . $courseLink . '">' . $learningObjective['txt'] . '</a>';
            $html .= '</span>';

            if ($lastVisitedObjId == $learningObjectiveObjId) {
                $html .= '<span class="last-visited triangle"></span>';
            }

            $html .= '<span class="icon-check icon-check-' . $classStatusCourses . '">';
            $html .= '<img src="' . LearningObjectiveSuggestionsTrackingToolConstants::PLUGIN_DIRECTORY . '/templates/images/' . $checkIcon . '">';
            $html .= '</span>';

            $html .= '<span class="count-courses ' . $classStatusCourses . '-courses">' . $learningObjective['count_completed_courses'] . ' von ' . count($learningObjective['courses']) . '</span>';
            if(!$notRecommendedCourses) {
                $html .= '<div class="container-weight">';
                $html .= '<span class="weight">' . $htmlIconsAlert . '</span>';
                $html .= '</div>';
            }
            $html .= '</div>';

            $html .= '<div class="container-percent-line">';
            $html .= '<div class="percent-line" style="width: ' . ($learningObjective['required_percentage'] ?? 0) . '%;">';
            $html .= '<div class="percent-line-percent"><span>' . ($learningObjective['required_percentage'] ?? 0) . '%</span></div>';
            $html .= '<div class="line">';
            $html .= '<div></div>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';

            $html .= '<div class="tracking-tool-panel" id="tracking-tool-panel-' . $learningObjectiveObjId . '-' . $this->userId . '">';
            $html .= '<div class="tracking-tool-test-required-percentage">';
            $html .= '</div>';

            $html .= $this->buildAccordionDropdownHtml(
                $learningObjective['courses'],
                $learningObjective['required_percentage']
            );

            $html .= '<div class="percent-line" style="width: ' . ($learningObjective['required_percentage'] ?? 0) . '%;">';
            $html .= '<div class="percent-line-percent"><span></span></div>';
            $html .= '<div class="line">';
            $html .= '<div></div>';
            $html .= '</div>';
            $html .= '</div>';


            $html .= '</div>';

            $index++;
        }
        return $html;
    }

    /**
     * @param int $courseObjId
     * @return string
     * @throws ilCtrlException
     */
    private function buildEntryTestButton(
        int $courseObjId
    ): string {
        $entryTest = $this->getDataEntryTest($courseObjId);

        if (!empty($entryTest)) {
            $linkEntryTestResults = $this->buildEntryTestResultsLink((int) $entryTest['itest']);
            $entryTestLink = $this->factory->link()->standard($this->pl->txt('entry_test'), $linkEntryTestResults);

            return $this->renderer->render($entryTestLink);
        }
        return '';
    }

    /**
     * @param int $objId
     * @return array
     */
    public static function getDataEntryTest(int $objId): array
    {
        global $DIC;

        $ilDB = $DIC->database();

        $result = $ilDB->queryF(
            "SELECT * FROM loc_settings
              WHERE obj_id = %s AND itest IS NOT NULL",
            ['integer'],
            [$objId]
        );

        $data = [];
        while ($row = $ilDB->fetchAssoc($result)) {
            $data = $row;
        }
        return $data;
    }

    /**
     * @param array    $learningObjectiveCourses
     * @param int|null $requiredPercentage
     * @return string
     */
    private function buildAccordionDropdownHtml(
        array $learningObjectiveCourses,
        ?int $requiredPercentage = null,
    ): string {
        $html = '';
        foreach ($learningObjectiveCourses as $k => $course) {
            $html .= '<div class="accordion-content">' . $course['title'] . '<span class="percentage">' . ($course['test_percentage'] ?? 0) . '%</span></div>';
            $html .= '<div class="tracking-tool-progress-container">';

            $targetLineClass = 'target-line';

            if ($course['test_percentage'] >= $course['test_required_percentage']) {
                $targetLineClass .= '-reached';
            }

            if ($requiredPercentage > 0) {
                $html .= '<div class="' . $targetLineClass . '" style="width: ' . $requiredPercentage . '%;"></div>';
            } else {
                $html .= '<div class="' . $targetLineClass . '"></div>';
            }

            $classProgressBar = 'progress-bar';
            if ($course['test_percentage'] !== null && $course['test_percentage'] >= $requiredPercentage) {
                $classProgressBar = 'progress-bar-percentage-completed';
            }

            $html .= '<div class="' . $classProgressBar . '" style="width: ' . ($course['test_percentage'] ?? 0) . '%;"></div>';
            $html .= '</div>';
        }
        return $html;
    }

    /**
     * @param int $crsObjectId
     * @return int|mixed
     */
    private function getCourseRefId(int $crsObjectId): mixed
    {
        $references = ilObject::_getAllReferences($crsObjectId);

        // Return the first reference ID if available, otherwise return 0
        return !empty($references) ? array_values($references)[0] : 0;
    }

    /**
     * @throws ilCtrlException
     */
    private function getCourseLink(): string
    {
        return $this->dic->ctrl()->getLinkTargetByClass(ilRepositoryGUI::class);
    }

    /**
     * @param $refId
     * @return void
     * @throws ilCtrlException
     */
    private function setRefIdAsClassParameter($refId): void
    {
        $this->ctrl->setParameterByClass(
            'ilrepositorygui',
            'ref_id',
            $refId
        );
    }

    /**
     * @param string      $htmlSuggestedCourses
     * @param string      $htmlNotSuggestedCourses
     * @param string|null $propertiesRefId
     * @return void
     * @throws ilCtrlException
     */
    private function setTemplateBlock(
        string $htmlSuggestedCourses,
        string $htmlNotSuggestedCourses,
        ?string $propertiesRefId = null
    ): void {
        $this->tpl->setCurrentBlock('tracking_tool');
        $this->setVariables($htmlSuggestedCourses, $htmlNotSuggestedCourses, $propertiesRefId);
        $this->tpl->parseCurrentBlock();
    }

    /**
     * @param string      $htmlSuggestedCourses
     * @param string      $htmlNotSuggestedCourses
     * @param string|null $propertiesRefId
     * @return void
     * @throws ilCtrlException
     * @throws Exception
     */
    private function setVariables(
        string $htmlSuggestedCourses,
        string $htmlNotSuggestedCourses,
        ?string $propertiesRefId = null
    ): void {
        $this->tpl->setVariable('TITLE', $this->pl->txt('title'));
        $this->tpl->setVariable('SUBTITLE', $this->pl->txt('subtitle'));
        $this->tpl->setVariable('DESCRIPTION', $this->pl->txt('description'));
        $this->tpl->setVariable('LEARNING_SUGGESTION', $this->pl->txt('learning_suggestion') . ' <span class="lets-get-started">' . $this->pl->txt('lets_get_started') . '</span>');
        $this->tpl->setVariable('HTML_SUGGESTED_COURSES', $htmlSuggestedCourses);

        if(!empty($propertiesRefId)) {
            $courses = $this->getUserCourses($this->userId);

            $accessToPrint = false;
            foreach ($courses as $course) {
                $courseRefId = $this->getCourseRefId($course['obj_id']);
                $certificateAccess = new ilParticipationCertificateAccess($courseRefId);

                if ($certificateAccess->isSelfPrintEnabled()) {
                    $accessToPrint = true;
                }
            }

            if (!$accessToPrint) {
                $this->tpl->setVariable('PRINT_BUTTON', '');
            } else {
                $printLink = $this->buildPrintLink($propertiesRefId);
                $printButton = '<a href="' . $printLink . '" class="print-button-visible">';
                $printButton .= '<img src="Customizing/global/plugins/Services/COPage/PageComponent/LearningObjectiveSuggestionsTrackingTool/templates/images/icon_file.svg" class="icon-file">';
                $printButton .= '</a>';

                $this->tpl->setVariable('PRINT_BUTTON', $printButton);
            }
        }

        $templateVariables = [
            'SUGGESTED_COURSES_TITLE' => $this->pl->txt('suggested_courses_title'),
            'NOT_SUGGESTED_COURSES_TITLE' => $this->pl->txt('not_suggested_courses_title'),
            'NOT_SUGGESTED_COURSES_HTML' => $htmlNotSuggestedCourses,
            'LEGENDS_TEXT_LEGENDS' => $this->pl->txt('legends_text_legends'),
            'LEGENDS_TEXT_SOS' => $this->pl->txt('legends_text_sos'),
            'LEGENDS_TEXT_SOS_2' => $this->pl->txt('legends_text_sos_2'),
            'LEGENDS_TEXT_LAST_VISIT' => $this->pl->txt('legends_text_last_visit'),
            'LEGENDS_TEXT_LAST_VISIT_2' => $this->pl->txt('legends_text_last_visit_2'),
            'LEGENDS_TEXT_PERCENT' => $this->pl->txt('legends_text_percent'),
            'LEGENDS_TEXT_PERCENT_2' => $this->pl->txt('legends_text_percent_2'),
            'LEGENDS_TEXT_PERCENT_3' => $this->pl->txt('legends_text_percent_3'),
            'LEGENDS_TEXT_COMPLETED' => $this->pl->txt('legends_text_completed'),
            'LEGENDS_TEXT_COMPLETED_2' => $this->pl->txt('legends_text_completed_2')
        ];

        foreach ($templateVariables as $variable => $value) {
            $this->tpl->setVariable($variable, $value);
        }
    }

    /**
     * @param string $propertiesRefId
     * @return string
     * @throws ilCtrlException
     */
    private function buildPrintLink(string $propertiesRefId): string
    {
        $itemRefId = $this->fetchUrlParameter('item_ref_id', FILTER_DEFAULT);
        $refId = $this->fetchUrlParameter('ref_id', FILTER_DEFAULT);

        $this->ctrl->setParameterByClass(
            'ilObjCategoryGUI',
            'ref_id',
            $refId
        );

        $this->ctrl->setParameterByClass(
            'ilObjCategoryGUI',
            'item_ref_id',
            $itemRefId
        );

        $this->ctrl->setParameterByClass(
            'ilObjCategoryGUI',
            'tracking_tool_ref_id',
            $propertiesRefId
        );

        return $this->dic->ctrl()->getLinkTargetByClass(
            [ilRepositoryGUI::class, ilObjCategoryGUI::class],
            'view'
        );
    }

    /**
     * @param int $entryTestReId
     * @return string
     * @throws ilCtrlException
     */
    private function buildEntryTestResultsLink(
        int $entryTestReId
    ): string {

        $this->ctrl->setParameterByClass(
            'ilTestEvalObjectiveOrientedGUI',
            'ref_id',
            $entryTestReId
        );

        return $this->dic->ctrl()->getLinkTargetByClass([
            ilObjTestGUI::class,
            ilTestResultsGUI::class,
            ilTestEvalObjectiveOrientedGUI::class
        ]);
    }

    /**
     * @return void
     * @throws CrossReferenceException
     * @throws LoaderError
     * @throws MpdfException
     * @throws PdfParserException
     * @throws PdfTypeException
     * @throws SyntaxError
     * @throws arException
     * @throws ilCtrlException
     * @throws ilDateTimeException
     * @throws Exception
     */
    private function printCertificate(): void
    {
        $refinery = $this->dic->refinery();
        $request = $this->dic->http()->wrapper()->post();

        $firstname = '';
        if ($request->has('form/user_data/firstname')) {
            $firstname = $request->retrieve(
                'form/user_data/firstname',
                $refinery->kindlyTo()->string()
            );

            if (empty($firstname)) {
                $this->ctrl->redirectByClass(
                    [ilRepositoryGUI::class, ilObjCategoryGUI::class],
                    'view'
                );
            }
        } elseif ($request->has('form/user_data/hidden_firstname')) {
            $firstname = $request->retrieve(
                'form/user_data/hidden_firstname',
                $refinery->kindlyTo()->string()
            );
        }

        $lastname = '';
        if ($request->has('form/user_data/lastname')) {
            $lastname = $request->retrieve(
                'form/user_data/lastname',
                $refinery->kindlyTo()->string()
            );

            if (empty($lastname)) {
                $this->ctrl->redirectByClass(
                    [ilRepositoryGUI::class, ilObjCategoryGUI::class],
                    'view'
                );
            }
        } elseif ($request->has('form/user_data/hidden_lastname')) {
            $lastname = $request->retrieve(
                'form/user_data/hidden_lastname',
                $refinery->kindlyTo()->string()
            );
        }

        $eMentoring = false;
        if ($request->has('form/ementoring')) {
            $eMentoring = true;
        }

        $homework = false;
        if ($request->has('form/homework')) {
            $homework = true;
        }

        $individualAssesments = false;
        if ($request->has('form/individual_assesments')) {
            $individualAssesments = true;
        }

        $sessions = false;
        if ($request->has('form/sessions')) {
            $sessions = true;
        }

        $userId = $this->dic->user()->getId();
        $courses = $this->getUserCourses($userId);

        $coursesToPrint = [];
        $printError = false;
        foreach ($courses as $course) {
            if ($request->has('form/options_' . $course['obj_id'] . '/course_suggested_courses_' . $course['obj_id'])) {
                $coursesToPrint[$course['obj_id']]['suggested_courses'] = true;
            }

            if ($request->has(
                'form/options_' . $course['obj_id'] . '/course_not_suggested_courses_' . $course['obj_id']
            )) {
                $coursesToPrint[$course['obj_id']]['additional_offer'] = true;
            }

            if ($request->has('form/options_' . $course['obj_id'] . '/course_final_test_' . $course['obj_id'])) {
                $coursesToPrint[$course['obj_id']]['final_test'] = true;
            }

            if (!empty($coursesToPrint[$course['obj_id']])) {
                $coursesToPrint[$course['obj_id']]['ref_id'] = $this->getCourseRefId($course['obj_id']);
            }
        }

        foreach ($coursesToPrint as $course) {
            $certificateAccess = new ilParticipationCertificateAccess($course['ref_id']);

            if (!$certificateAccess->isSelfPrintEnabled()) {
                $printError = true;
            }
        }

        if ($printError) {
            $tpl = $this->dic->ui()->mainTemplate();
            $tpl->setOnScreenMessage('failure', $this->lng->txt('no_permission'), true);

            echo json_encode([
                'success' => false
            ]);
            exit;
        }

        $coursesToPrint = $this->excludeCoursesWithNoPrintPermission($coursesToPrint);

        if (empty($coursesToPrint)) {
            $tpl = $this->dic->ui()->mainTemplate();
            $tpl->setOnScreenMessage('failure',$this->pl->txt('no_courses_selected'), true);
            echo json_encode([
                'success' => false
            ]);
            exit;
        }

        if (count(array_keys($coursesToPrint)) === 1) {
            $courseObjIdToPrint = array_key_first($coursesToPrint);
            $course = $coursesToPrint[$courseObjIdToPrint];

            $twigParser = new ilParticipationCertificateTwigParser(
                $course['ref_id'],
                [$this->userId],
                $eMentoring,
                false,
                null,
                true
            );

            $twigParser->parseData(
                true,
                true,
                $course['ref_id'],
                isset($course['suggested_courses']),
                isset($course['additional_offer']),
                isset($course['final_test']),
                $homework,
                $individualAssesments,
                $sessions,
                $firstname,
                $lastname
            );

        } else {
            $twigParser = new ilParticipationCertificateTwigParser(
                null,
                [$this->userId],
                $eMentoring,
                false,
                null,
                true
            );

            $domain = $this->dic->container()
                                ->internal()
                                ->domain();

            foreach ($coursesToPrint as $courseObjectId => $course) {
                $courseContainerId = $this->dic->repositoryTree()->getParentId($course['ref_id']);
                $groupRefId = $this->getGroupOfContainer($courseContainerId, $domain);
                $coursesToPrint[$courseObjectId]['group_id'] = $groupRefId;
            }

            $twigParser->parseDataMultipleCourses(
                $coursesToPrint,
                $firstname,
                $lastname,
                $this->dic->user()->getId(),
                true,
                $homework,
                $individualAssesments,
                $sessions
            );
        }
    }

    /**
     * @param int                   $containerRefId
     * @param InternalDomainService $domain
     * @return int|null
     * @throws ilDatabaseException
     * @throws ilObjectNotFoundException
     */

    public function getGroupOfContainer(
        int $containerRefId,
        $domain
    ): int|null {
        $containerObjectFactory = \ilObjectFactory::getInstanceByRefId($containerRefId);

        $itemPresentation = $domain
            ->content()
            ->itemPresentation(
                $containerObjectFactory,
                null,
                false
            );

        $items = $itemPresentation->getAllRefIds();
        $groupRefId = null;
        foreach ($items as $itemRefId) {
            $itemObject = \ilObjectFactory::getInstanceByRefId($itemRefId);

            if ($itemObject->getType() === 'grp') {
                $groupRefId = (int) $itemRefId;

                break;
            }
        }
        return $groupRefId;
    }

    /**
     * @param array $coursesToPrint
     * @return array
     * @throws Exception
     */
    #[NoReturn]
    private function excludeCoursesWithNoPrintPermission(array $coursesToPrint): array
    {
        foreach ($coursesToPrint as $objId => $course) {
            $courseRefId = $this->getCourseRefId($objId);
            $certificateAccess = new ilParticipationCertificateAccess($courseRefId);

            if (!$certificateAccess->hasCurrentUserPrintAccess(true)) {
                unset($coursesToPrint[$objId]);
            }
        }
        return $coursesToPrint;
    }

    /**
     * @param int $userId
     * @return array
     */
    public static function getUserCourses(int $userId): array
    {
        global $DIC;
        $ilDB = $DIC->database();

        $courses = CourseConfig::get();
        $courseObjIds = array_map(function($config) {
            return $config->getCourseObjId();
        }, $courses);

        $uniqueCourseObjIds = array_values(array_unique($courseObjIds));

        $in = $ilDB->in('obj_id', $uniqueCourseObjIds, false, 'integer');

        $result = $ilDB->queryF(
            "SELECT * FROM obj_members
              WHERE usr_id = %s AND $in AND member = 1",
            ['integer'],
            [$userId]
        );

        $data = [];
        while ($row = $ilDB->fetchAssoc($result)) {
            $data[] = $row;
        }

        return $data;
    }

    /**
     * @param string $param
     * @param int    $filter
     * @return int|null
     */
    protected function fetchUrlParameter(string $param, int $filter): ?int
    {
        return filter_input(INPUT_GET, $param, $filter);
    }
}
