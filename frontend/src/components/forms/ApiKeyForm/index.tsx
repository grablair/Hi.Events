import {UseFormReturnType} from "@mantine/form";
import {MultiSelect, TextInput} from "@mantine/core";
import {CreateApiKeyRequest} from "../../../types.ts";
import {t} from "@lingui/macro";

interface ApiKeyFormProps {
    form: UseFormReturnType<CreateApiKeyRequest>,
}

// TODO: translations
export const ApiKeyForm = ({form}: ApiKeyFormProps) => {
    return (
        <>
            <TextInput {...form.getInputProps('token_name')} label={`Token Name`} placeholder="my-python-script" required/>

            <MultiSelect
                placeholder={`All Permissions`}
                label={`What permissions should be granted to this API key? (Applies to all by default)`}
                searchable
                data={[
                    {
                        value: "users",
                        label: "Users"
                    },
                    {
                        value: "accounts",
                        label: "Accounts"
                    },
                    {
                        value: "organizers",
                        label: "Organizers"
                    },
                    {
                        value: "taxes-and-fees",
                        label: "Taxes and Fees"
                    },
                    {
                        value: "events",
                        label: "Events"
                    },
                ]}
                {...form.getInputProps('abilities')}
            />

            <TextInput type={'datetime-local'}
                       {...form.getInputProps('expires_at')}
                       label={t`Expiry Date`}
            />
        </>
    );
};
