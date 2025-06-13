package com.novemax.expense.mapper;

import com.novemax.expense.dto.request.ExpenseRequestDTO;
import com.novemax.expense.dto.response.ExpenseResponseDTO;
import com.novemax.expense.model.Expense;
import org.mapstruct.InheritInverseConfiguration;
import org.mapstruct.Mapper;
import org.mapstruct.Mapping;

@Mapper(componentModel = "spring", uses = UserMapper.class)
public interface ExpenseMapper {

    @Mapping(source = "userCreator.id", target = "userCreator.id")
    @Mapping(source = "userPayer.id", target = "userPayer.id")
    @Mapping(source = "group.id", target = "group.id")
    ExpenseResponseDTO toResponseDTO(Expense entity);

    @Mapping(source = "userPayerId", target = "userPayer.id")
    @Mapping(source = "userCreatorId", target = "userCreator.id")
    @Mapping(source = "groupId", target = "group.id")
    @Mapping(source = "payerIds", target = "payers")
    Expense toEntity(ExpenseRequestDTO dto);

    @InheritInverseConfiguration(name = "toResponseDTO")
    Expense toEntity(ExpenseResponseDTO dto);

    @InheritInverseConfiguration(name = "toEntity")
    ExpenseRequestDTO toRequestDTO(Expense entity);
}
