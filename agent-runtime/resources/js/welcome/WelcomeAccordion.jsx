import { useState } from 'react';
import { ChartNoAxesCombined, ChevronDown, FileText, FolderKanban, Plus, ScanSearch, Tags, Trash2 } from 'lucide-react';
import { moveQuestion } from './welcomeQuestions.js';

const ICONS = { ScanSearch, Tags, FolderKanban, ChartNoAxesCombined, FileText };

export function WelcomeAccordion({ modules, openId, onToggle, onPick, onChangeModules, compact = false }) {
    function update(moduleId, questions) {
        onChangeModules(modules.map((module) => module.id === moduleId ? { ...module, questions } : module));
    }

    function add(moduleId) {
        const text = window.prompt('Câu hỏi mới');
        const trimmed = String(text || '').trim();
        if (trimmed === '') return;
        const module = modules.find((item) => item.id === moduleId);
        update(moduleId, [...(module?.questions || []), { id: `user-${Date.now()}`, text: trimmed, system: false }]);
    }

    function edit(moduleId, question) {
        const text = window.prompt('Sửa câu hỏi', question.text);
        const trimmed = String(text || '').trim();
        if (trimmed === '') return;
        const module = modules.find((item) => item.id === moduleId);
        update(moduleId, (module?.questions || []).map((item) => item.id === question.id ? { ...item, text: trimmed } : item));
    }

    return (
        <div className={`agent-welcome-accordion${compact ? ' is-compact' : ''}`}>
            {modules.map((module) => {
                const open = openId === module.id;
                const Icon = ICONS[module.icon] || FileText;
                const questions = module.questions || [];
                return (
                    <section key={module.id} className={`agent-welcome-module is-${module.tone}${open ? ' is-open' : ''}`}>
                        <button
                            type="button"
                            className="agent-welcome-module__header"
                            aria-expanded={open}
                            onClick={() => onToggle(module.id)}
                        >
                            <Icon size={15} aria-hidden="true" />
                            <span>{module.label}</span>
                            <span className="agent-welcome-module__count">{questions.length}</span>
                            <ChevronDown size={14} className="agent-welcome-module__chevron" aria-hidden="true" />
                        </button>
                        {open ? (
                            <div className="agent-welcome-module__body">
                                {questions.map((question, index) => (
                                    <div key={question.id} className="agent-welcome-question">
                                        <button type="button" className="agent-welcome-question__text" onClick={() => onPick(question.text)}>
                                            {question.text}
                                        </button>
                                        {question.system ? null : (
                                            <span className="agent-welcome-question__tools">
                                                <button type="button" aria-label="Move question up" onClick={() => update(module.id, moveQuestion(questions, index, -1))}>↑</button>
                                                <button type="button" aria-label="Move question down" onClick={() => update(module.id, moveQuestion(questions, index, 1))}>↓</button>
                                                <button type="button" aria-label="Edit question" onClick={() => edit(module.id, question)}>✎</button>
                                                <button type="button" aria-label="Remove question" onClick={() => update(module.id, questions.filter((item) => item.id !== question.id))}><Trash2 size={12} /></button>
                                            </span>
                                        )}
                                    </div>
                                ))}
                                <button type="button" className="agent-welcome-add" onClick={() => add(module.id)}>
                                    <Plus size={13} aria-hidden="true" />
                                    <span>Thêm câu hỏi</span>
                                </button>
                            </div>
                        ) : null}
                    </section>
                );
            })}
        </div>
    );
}

export function useWelcomeModule(initialId = 'seo_audit') {
    const [openId, setOpenId] = useState(initialId);
    return [openId, (id) => setOpenId((current) => (current === id ? '' : id))];
}
